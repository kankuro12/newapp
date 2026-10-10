<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained('users');
            $table->boolean('operational_email')->default(true);
            $table->boolean('promotional_email')->default(false);
            $table->boolean('operational_fcm')->default(false);
            $table->boolean('promotional_fcm')->default(false);
            $table->boolean('operational_whatsapp')->default(false);
            $table->boolean('promotional_whatsapp')->default(false);
            $table->timestamps();
        });
        Schema::create('message_deliveries', function (Blueprint $table) {
            $table->id();
            $table->string('event_key', 64)->unique();
            $table->string('event', 40);
            $table->foreignId('user_id')->constrained('users');
            $table->foreignId('billing_account_id')->nullable()->constrained('billing_accounts');
            $table->string('channel', 15);
            $table->longText('context_encrypted');
            $table->string('status', 20)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('error_code', 50)->nullable();
            $table->string('provider_id')->nullable();
            $table->dateTime('attempted_at')->nullable();
            $table->dateTime('sent_at')->nullable();
            $table->dateTime('next_dispatch_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'next_dispatch_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_deliveries');
        Schema::dropIfExists('notification_preferences');
    }
};
