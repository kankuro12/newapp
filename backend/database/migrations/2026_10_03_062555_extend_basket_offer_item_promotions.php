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
        Schema::table('basket_offers', function (Blueprint $t) {
            $t->string('offer_kind', 20)->default('basket');
            $t->unsignedInteger('maximum_applications')->nullable();
            $t->json('rules')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('basket_offers', function (Blueprint $t) {
            $t->dropColumn(['offer_kind', 'maximum_applications', 'rules']);
        });
    }
};
