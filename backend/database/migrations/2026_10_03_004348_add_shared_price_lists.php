<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_lists', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->unique(['tenant_id', 'id']);
            $t->string('name', 100);
            $t->string('channel', 10);
            $t->boolean('enabled')->default(true);
            $t->integer('adjustment_bps')->default(0);
            $t->unsignedInteger('starts_bs')->nullable();
            $t->unsignedInteger('ends_bs')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->unique(['tenant_id', 'name', 'channel']);
            $t->timestamps();
        });
        Schema::create('price_list_rates', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->unsignedBigInteger('price_list_id');
            $t->unsignedBigInteger('item_id');
            $t->foreign(['tenant_id', 'price_list_id'])->references(['tenant_id', 'id'])->on('price_lists');
            $t->foreign(['tenant_id', 'item_id'])->references(['tenant_id', 'id'])->on('items');
            $t->unsignedBigInteger('min_qty_milli');
            $t->unsignedBigInteger('price_paisa');
            $t->string('unit_snapshot', 30);
            $t->string('pos_unit', 20);
            $t->string('item_kind', 20);
            $t->unique(['tenant_id', 'price_list_id', 'item_id', 'min_qty_milli'], 'price_list_tier_unique');
        });
        Schema::table('contacts', function (Blueprint $t) {
            foreach (['sales_price_list_id', 'purchase_price_list_id'] as $field) {
                $t->unsignedBigInteger($field)->nullable();
                $t->foreign(['tenant_id', $field])->references(['tenant_id', 'id'])->on('price_lists');
            }
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $t) {
            foreach (['sales_price_list_id', 'purchase_price_list_id'] as $field) {
                $t->dropForeign(['tenant_id', $field]);
                $t->dropColumn($field);
            }
        });
        Schema::dropIfExists('price_list_rates');
        Schema::dropIfExists('price_lists');
    }
};
