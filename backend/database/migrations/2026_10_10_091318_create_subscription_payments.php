<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $t) {
            $t->boolean('package_enabled')->default(true);
        });
        Schema::create('subscription_payments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('billing_account_id')->constrained('billing_accounts');
            $t->foreignId('actor_id')->constrained('users');
            $t->uuid('mutation_uuid');
            $t->string('request_hash', 64);
            $t->uuid('reference')->unique();
            $t->string('gateway', 20);
            $t->string('currency', 3);
            $t->unsignedBigInteger('amount_minor');
            $t->json('package_snapshot');
            $t->json('retained_business_ids');
            $t->boolean('retained_selection')->default(false);
            $t->string('status', 20)->default('created');
            $t->string('provider_id')->nullable();
            $t->string('provider_transaction_id')->nullable();
            $t->json('checkout_data')->nullable();
            $t->foreignId('subscription_id')->nullable()->unique()->constrained('billing_subscriptions');
            $t->timestamps();
            $t->unique(['billing_account_id', 'mutation_uuid']);
            $t->unique(['gateway', 'provider_transaction_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_payments');
        Schema::table('tenants', function (Blueprint $t) {
            $t->dropColumn('package_enabled');
        });
    }
};
