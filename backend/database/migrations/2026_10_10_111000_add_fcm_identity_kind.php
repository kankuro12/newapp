<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_devices', fn (Blueprint $table) => $table->string('target_kind', 10)->default('token'));
    }

    public function down(): void
    {
        Schema::table('notification_devices', fn (Blueprint $table) => $table->dropColumn('target_kind'));
    }
};
