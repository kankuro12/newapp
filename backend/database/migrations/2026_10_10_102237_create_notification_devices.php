<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users');
            $table->uuid('device_uuid');
            $table->string('name', 100);
            $table->string('token_hash', 64)->nullable()->unique();
            $table->longText('token_encrypted')->nullable();
            $table->boolean('active')->default(true);
            $table->dateTime('last_seen_at');
            $table->dateTime('revoked_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'device_uuid']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_devices');
    }
};
