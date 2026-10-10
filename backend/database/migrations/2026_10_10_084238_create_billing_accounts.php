<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_accounts', function (Blueprint $t) {
            $t->id();
            $t->string('name', 150);
            $t->string('status', 20)->default('active');
            $t->timestamp('trial_used_at')->nullable();
            $t->unsignedBigInteger('version')->default(1);
            $t->timestamps();
        });
        Schema::create('billing_account_user', function (Blueprint $t) {
            $t->foreignId('billing_account_id')->constrained('billing_accounts');
            $t->foreignId('user_id')->constrained('users');
            $t->string('role', 20);
            $t->boolean('active')->default(true);
            $t->unique(['billing_account_id', 'user_id']);
        });
        Schema::table('tenants', function (Blueprint $t) {
            $t->foreignId('billing_account_id')->nullable()->constrained('billing_accounts');
        });
        $this->backfill();
    }

    public function backfill(): void
    {
        DB::table('tenants')->whereNull('billing_account_id')->whereNull('parent_tenant_id')->orderBy('id')->chunkById(100, function ($businesses) {
            foreach ($businesses as $business) {
                $account = DB::table('billing_accounts')->insertGetId(['name' => $business->name, 'status' => 'active', 'trial_used_at' => $business->created_at ?? now(), 'created_at' => now(), 'updated_at' => now()]);
                DB::table('tenants')->where('id', $business->id)->orWhere('parent_tenant_id', $business->id)->update(['billing_account_id' => $account]);
                foreach (DB::table('tenant_user')->where('tenant_id', $business->id)->where('role', 'owner')->get() as $owner) {
                    DB::table('billing_account_user')->insert(['billing_account_id' => $account, 'user_id' => $owner->user_id, 'role' => 'owner', 'active' => $owner->active]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $t) {
            $t->dropConstrainedForeignId('billing_account_id');
        });
        Schema::dropIfExists('billing_account_user');
        Schema::dropIfExists('billing_accounts');
    }
};
