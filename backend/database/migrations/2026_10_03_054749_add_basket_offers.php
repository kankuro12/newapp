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
        Schema::create('basket_offers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->unique(['tenant_id', 'id']);
            $t->string('name', 100);
            $t->unique(['tenant_id', 'name']);
            $t->boolean('enabled')->default(true);
            $t->string('discount_mode', 10);
            $t->unsignedBigInteger('discount_value');
            $t->unsignedBigInteger('minimum_spend_paisa')->default(0);
            $t->unsignedBigInteger('maximum_discount_paisa')->nullable();
            $t->unsignedInteger('starts_bs')->nullable();
            $t->unsignedInteger('ends_bs')->nullable();
            $t->boolean('cashier_allowed')->default(true);
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
        });
        Schema::table('documents', function (Blueprint $t) {
            $t->unsignedBigInteger('basket_offer_id')->nullable();
            $t->foreign(['tenant_id', 'basket_offer_id'])->references(['tenant_id', 'id'])->on('basket_offers');
            $t->json('basket_offer_snapshot')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('documents', function (Blueprint $t) {
            $t->dropForeign(['tenant_id', 'basket_offer_id']);
            $t->dropColumn(['basket_offer_id', 'basket_offer_snapshot']);
        });
        Schema::dropIfExists('basket_offers');
    }
};
