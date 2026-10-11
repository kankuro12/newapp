<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_subscriptions', function (Blueprint $table) {
            $table->json('retained_business_ids')->nullable();
            $table->dateTime('applied_at')->nullable();
            $table->dateTime('revoked_at')->nullable();
            $table->index(['applied_at', 'start_at']);
        });
        // Existing subscriptions already had their business allowance applied at settlement.
        DB::table('billing_subscriptions')->update(['applied_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        Schema::table('billing_subscriptions', function (Blueprint $table) {
            $table->dropIndex(['applied_at', 'start_at']);
            $table->dropColumn(['retained_business_ids', 'applied_at', 'revoked_at']);
        });
    }
};
