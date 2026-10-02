<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class PlatformController extends Controller
{
    public function login(Request $r): Response
    {
        $input = $r->validate(['email' => 'required|email', 'password' => 'required|string']);
        $input['email'] = strtolower($input['email']);
        if (! Auth::guard('superadmin')->attempt($input)) {
            throw ValidationException::withMessages(['email' => 'Credentials incorrect.']);
        } $r->session()->regenerate();

        return response()->noContent();
    }

    public function logout(Request $r): Response
    {
        Auth::guard('superadmin')->logout();
        $r->session()->regenerate(true);
        $r->session()->regenerateToken();

        return response()->noContent();
    }

    public function me(): JsonResponse
    {
        return response()->json(['data' => BusinessController::json(auth('superadmin')->user()->only(['id', 'name', 'email']))])->header('Cache-Control', 'no-store');
    }

    public function tenants(Request $r): JsonResponse
    {
        $input = $r->validate(['q' => 'nullable|string|max:100', 'page' => 'nullable|integer|min:1']);
        $q = Tenant::query();
        if (! empty($input['q'])) {
            $q->where('name', 'like', '%'.$input['q'].'%');
        } $page = $q->orderByDesc('id')->paginate(30);
        $page->getCollection()->transform(function ($tenant) {
            $owner = DB::table('tenant_user')->where('tenant_id', $tenant->id)->where('role', 'owner')->where('active', true)->join('users', 'users.id', '=', 'tenant_user.user_id')->select('users.email')->first();

            return [...$tenant->only(['id', 'slug', 'name', 'access_status', 'trial_ends_at', 'access_until', 'created_at']), 'owner_email' => $owner?->email, 'staff_count' => (string) DB::table('tenant_user')->where('tenant_id', $tenant->id)->where('active', true)->count(), 'document_count' => (string) DB::table('documents')->where('tenant_id', $tenant->id)->count()];
        });

        return response()->json(BusinessController::json($page->toArray()))->header('Cache-Control', 'no-store');
    }

    public function access(Request $r, int $id): JsonResponse
    {
        $input = $r->validate(['access_status' => 'required|in:trial,active,expired,suspended', 'access_until' => 'nullable|date', 'reason' => 'required|string|min:5|max:500', 'password' => 'required|string']);
        abort_unless(Hash::check($input['password'], auth('superadmin')->user()->password), 403, 'Password incorrect.');
        if (in_array($input['access_status'], ['active', 'trial']) && (empty($input['access_until']) || strtotime($input['access_until']) <= time())) {
            throw ValidationException::withMessages(['access_until' => 'Choose future access expiry.']);
        }
        $tenant = DB::transaction(function () use ($id, $input) {
            $tenant = Tenant::whereKey($id)->lockForUpdate()->firstOrFail();
            $tenant->update(['access_status' => $input['access_status'], 'access_until' => $input['access_until'] ?? null, 'trial_ends_at' => $input['access_status'] === 'trial' ? $input['access_until'] : $tenant->trial_ends_at]);
            $tenant->increment('data_version');
            DB::table('audit_logs')->insert(['tenant_id' => $id, 'actor_id' => auth('superadmin')->id(), 'actor_guard' => 'superadmin', 'action' => 'access.updated', 'subject_type' => 'tenants', 'subject_id' => $id, 'metadata' => json_encode(['reason' => $input['reason'], 'status' => $input['access_status']]), 'created_at' => now()]);

            return $tenant;
        });

        return response()->json(['data' => BusinessController::json($tenant->only(['id', 'name', 'access_status', 'access_until']))]);
    }
}
