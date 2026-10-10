<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_device_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_delivery_id')->constrained('message_deliveries');
            $table->foreignId('notification_device_id')->constrained('notification_devices');
            $table->string('status', 20);
            $table->string('error_code', 50)->nullable();
            $table->string('provider_id')->nullable();
            $table->timestamps();
            $table->unique(['message_delivery_id', 'notification_device_id'], 'message_device_once');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_device_deliveries');
    }
};
