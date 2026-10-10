<?php

namespace App\Http\Controllers;

use App\Service\BillingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class PackageController extends Controller
{
    public function index(BillingService $billing): JsonResponse
    {
        $rows = DB::table('billing_packages')->orderBy('id')->get()->map(function ($p) {
            $p->products = json_decode($p->products, true);
            $p->active = (bool) $p->active;
            $p->prices = DB::table('billing_package_prices')->where('package_id', $p->id)->get()->map(fn ($price) => ['currency' => $price->currency, 'amount_minor' => (string) $price->amount_minor])->all();

            return $p;
        });

        return response()->json(['data' => BusinessController::json($rows)])->header('Cache-Control', 'no-store');
    }

    public function save(Request $request, ?int $id = null): JsonResponse
    {
        $input = $request->validate(['name' => 'required|string|max:150', 'duration_days' => 'required|integer|min:1|max:3650',
            'trial_days' => 'required|integer|min:0|max:365', 'max_businesses' => 'required|integer|min:1|max:10000',
            'products' => 'required|array|min:1|max:9', 'products.*' => ['required', 'distinct', Rule::in(['bookkeeping', 'meat', 'restaurant', 'barber', 'salon', 'milk', 'glass', 'wood', 'gym'])],
            'prices' => 'required|array|min:1|max:3', 'prices.*.currency' => 'required|in:NPR,USD,EUR|distinct',
            'prices.*.amount_minor' => ['required', 'regex:/^[1-9][0-9]{0,11}$/'], 'active' => 'required|boolean',
            'version' => 'required|integer|min:1', 'password' => 'required|string', 'reason' => 'required|string|min:5|max:500']);
        abort_unless(Hash::check($input['password'], auth('superadmin')->user()->password), 403, 'Password incorrect.');
        $result = DB::transaction(function () use ($id, $input) {
            if ($id) {
                $old = DB::table('billing_packages')->where('id', $id)->lockForUpdate()->first() ?? abort(404);
                abort_unless((int) $old->version === (int) $input['version'], 409, 'Package changed. Reload.');
            }
            $values = ['name' => $input['name'], 'duration_days' => $input['duration_days'], 'trial_days' => $input['trial_days'], 'max_businesses' => $input['max_businesses'], 'products' => json_encode($input['products']), 'active' => $input['active'], 'version' => $id ? $input['version'] + 1 : 1, 'updated_at' => now()];
            if ($id) {
                DB::table('billing_packages')->where('id', $id)->update($values);
            } else {
                $id = DB::table('billing_packages')->insertGetId([...$values, 'created_at' => now()]);
            }
            DB::table('billing_package_prices')->where('package_id', $id)->delete();
            foreach ($input['prices'] as $price) {
                DB::table('billing_package_prices')->insert(['package_id' => $id, ...$price]);
            }
            DB::table('billing_audit_logs')->insert(['actor_id' => auth('superadmin')->id(), 'actor_guard' => 'superadmin', 'action' => 'package.saved', 'subject_id' => $id, 'metadata' => json_encode(['reason' => $input['reason'], 'version' => $values['version']]), 'created_at' => now()]);

            return DB::table('billing_packages')->where('id', $id)->first();
        }, 3);

        return response()->json(['data' => BusinessController::json($result)], $id ? 200 : 201);
    }
}
