<?php

namespace Database\Seeders;

use App\Models\SuperAdmin;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Local development only: a verified owner for trying the gym desk, plus a platform administrator.
 * Run with `php84 artisan db:seed --class=GymDemoSeeder`; sign in as gym-demo@example.test / gym-demo-password.
 * Cashier for role checks: cashier-demo@example.test / cashier-demo-password, joined to the owner's businesses.
 * Browser sign-up test account (registered through the UI, not seeded): test@dairyco.com.np / TenantFlow-Test-2083.
 */
class GymDemoSeeder extends Seeder
{
    public function run(): void
    {
        abort_if(app()->environment('production'), 403, 'Demo seeder is for local development.');
        User::query()->updateOrCreate(['email' => 'gym-demo@example.test'], [
            'name' => 'Gym Demo Owner', 'phone' => '9800000099', 'country_code' => 'NP',
            'password' => Hash::make('gym-demo-password'), 'email_verified_at' => now(),
        ]);
        $owner = User::query()->where('email', 'gym-demo@example.test')->value('id');
        $cashier = User::query()->updateOrCreate(['email' => 'cashier-demo@example.test'], [
            'name' => 'Cashier Demo', 'phone' => '9800000098', 'country_code' => 'NP',
            'password' => Hash::make('cashier-demo-password'), 'email_verified_at' => now(),
        ]);
        foreach (DB::table('tenant_user')->where('user_id', $owner)->where('role', 'owner')->pluck('tenant_id') as $tenant) {
            DB::table('tenant_user')->updateOrInsert(['tenant_id' => $tenant, 'user_id' => $cashier->id], ['role' => 'cashier', 'active' => true]);
        }
        // Local platform panel login: platform-demo@example.test / platform-demo-password.
        SuperAdmin::query()->updateOrCreate(['email' => 'platform-demo@example.test'], ['name' => 'Platform Demo', 'password' => 'platform-demo-password']);
    }
}
