<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('basket_offers', function (Blueprint $table) {
            $table->unsignedSmallInteger('starts_minute')->nullable();
            $table->unsignedSmallInteger('ends_minute')->nullable();
            $table->json('weekdays')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('basket_offers', function (Blueprint $table) {
            $table->dropColumn(['starts_minute', 'ends_minute', 'weekdays']);
        });
    }
};
