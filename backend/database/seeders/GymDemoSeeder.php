<?php

namespace Database\Seeders;

use App\Models\SuperAdmin;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Local development only: a verified owner for trying the gym desk, plus a platform administrator.
 * Run with `php84 artisan db:seed --class=GymDemoSeeder`; sign in as gym-demo@example.test / gym-demo-password.
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
        // Local platform panel login: platform-demo@example.test / platform-demo-password.
        SuperAdmin::query()->updateOrCreate(['email' => 'platform-demo@example.test'], ['name' => 'Platform Demo', 'password' => 'platform-demo-password']);
    }
}
