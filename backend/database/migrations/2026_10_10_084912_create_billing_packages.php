<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_accounts', function (Blueprint $t) {
            $t->boolean('legacy_access')->default(false);
        });
        DB::table('billing_accounts')->whereNotNull('trial_used_at')->orWhereIn('id', DB::table('tenants')->whereNotNull('billing_account_id')->select('billing_account_id'))->update(['legacy_access' => true]);
        Schema::create('billing_packages', function (Blueprint $t) {
            $t->id();
            $t->string('name', 150);
            $t->unsignedInteger('duration_days');
            $t->unsignedInteger('trial_days');
            $t->unsignedInteger('max_businesses');
            $t->json('products');
            $t->boolean('active')->default(true);
            $t->unsignedBigInteger('version')->default(1);
            $t->timestamps();
        });
        Schema::create('billing_package_prices', function (Blueprint $t) {
            $t->id();
            $t->foreignId('package_id')->constrained('billing_packages');
            $t->string('currency', 3);
            $t->unsignedBigInteger('amount_minor');
            $t->unique(['package_id', 'currency']);
        });
        Schema::create('billing_subscriptions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('billing_account_id')->constrained('billing_accounts');
            $t->foreignId('package_id')->constrained('billing_packages');
            $t->json('package_snapshot');
            $t->string('status', 20);
            $t->dateTime('start_at');
            $t->dateTime('end_at');
            $t->timestamps();
            $t->index(['billing_account_id', 'id']);
        });
        Schema::create('billing_audit_logs', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('actor_id');
            $t->string('actor_guard', 20);
            $t->string('action', 100);
            $t->unsignedBigInteger('subject_id');
            $t->json('metadata');
            $t->timestamp('created_at');
        });
        DB::table('billing_packages')->insert(['name' => 'Standard trial', 'duration_days' => 30, 'trial_days' => 14, 'max_businesses' => 100,
            'products' => json_encode(['bookkeeping', 'meat', 'restaurant', 'barber', 'salon', 'milk', 'glass', 'wood', 'gym']), 'active' => true, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('billing_accounts', function (Blueprint $t) {
            $t->dropColumn('legacy_access');
        });
        Schema::dropIfExists('billing_audit_logs');
        Schema::dropIfExists('billing_subscriptions');
        Schema::dropIfExists('billing_package_prices');
        Schema::dropIfExists('billing_packages');
    }
};
