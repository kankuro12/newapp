<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_payments', function (Blueprint $table) {
            $table->dateTime('last_checked_at')->nullable();
            $table->dateTime('next_check_at')->nullable();
            $table->dateTime('reconcile_until')->nullable();
            $table->uuid('reconcile_token')->nullable();
            $table->string('last_error_code', 45)->nullable();
            $table->index(['status', 'next_check_at']);
        });
    }

    public function down(): void
    {
        Schema::table('subscription_payments', function (Blueprint $table) {
            $table->dropIndex(['status', 'next_check_at']);
            $table->dropColumn(['last_checked_at', 'next_check_at', 'reconcile_until', 'reconcile_token', 'last_error_code']);
        });
    }
};
