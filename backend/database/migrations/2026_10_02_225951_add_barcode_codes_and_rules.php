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
        Schema::table('tenants', fn (Blueprint $t) => $t->json('barcode_rules')->nullable());
        Schema::create('item_codes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->unsignedBigInteger('item_id');
            $t->string('code', 100);
            $t->unique(['tenant_id', 'code']);
            $t->foreign(['tenant_id', 'item_id'])->references(['tenant_id', 'id'])->on('items');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('item_codes');
        Schema::table('tenants', fn (Blueprint $t) => $t->dropColumn('barcode_rules'));
    }
};
