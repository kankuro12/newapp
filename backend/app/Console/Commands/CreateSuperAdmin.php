<?php

namespace App\Console\Commands;

use App\Models\SuperAdmin;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class CreateSuperAdmin extends Command
{
    protected $signature = 'app:create-super-admin {email} {--name=Platform administrator}';

    protected $description = 'Provision separate super-admin identity; password entered privately';

    public function handle(): int
    {
        $input = ['email' => strtolower($this->argument('email')), 'name' => $this->option('name'), 'password' => $this->secret('Password (minimum 12 characters)')];
        $validator = Validator::make($input, ['email' => 'required|email|unique:super_admins,email', 'name' => 'required|string|max:255', 'password' => 'required|string|min:12']);
        if ($validator->fails()) {
            $this->error($validator->errors()->first());

            return self::FAILURE;
        }
        $admin = DB::transaction(function () use ($input) {
            $admin = SuperAdmin::create($input);
            DB::table('audit_logs')->insert(['actor_id' => $admin->id, 'actor_guard' => 'superadmin', 'action' => 'admin.provisioned', 'subject_type' => 'super_admins', 'subject_id' => $admin->id, 'metadata' => '{}', 'created_at' => now()]);

            return $admin;
        });
        $this->info('Super-admin created: '.$admin->email);

        return self::SUCCESS;
    }
}
