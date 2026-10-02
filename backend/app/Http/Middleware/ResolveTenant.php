<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Support\CurrentTenant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class ResolveTenant
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = Tenant::where('slug', $request->route('tenant'))->firstOrFail();
        $user = auth('tenant')->user();
        abort_unless($user && ! $user->disabled_at, 401);
        $membership = DB::table('tenant_user')->where('tenant_id', $tenant->id)->where('user_id', $user->id)->where('active', true)->first();
        abort_unless($membership, 404);
        abort_if($tenant->access_status === 'suspended', 403, 'Business suspended.');
        $expiry = $tenant->access_status === 'trial' ? $tenant->trial_ends_at : $tenant->access_until;
        $expired = ! in_array($tenant->access_status, ['active', 'trial']) || ! $expiry || ! $expiry->isFuture();
        if ($expired) {
            abort_unless(in_array($membership->role, ['owner', 'accountant']) && $request->isMethod('GET'), 403, 'Business access expired.');
        }
        app(CurrentTenant::class)->set($tenant);
        $request->attributes->set('tenant', $tenant);
        $request->attributes->set('role', $membership->role);
        try {
            $response = $request->isMethod('GET') ? DB::transaction(fn () => $next($request)) : $next($request);
            $response->headers->set('Cache-Control', 'no-store, private');

            return $response;
        } finally {
            app(CurrentTenant::class)->clear();
        }
    }
}
