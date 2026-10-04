<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('item_categories', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->unique(['tenant_id', 'id']);
            $t->string('name', 150);
            $t->unique(['tenant_id', 'name']);
            $t->unsignedInteger('version')->default(1);
            $t->timestamp('archived_at')->nullable();
            $t->timestamps();
        });
        Schema::table('items', function (Blueprint $t) {
            $t->unsignedBigInteger('category_id')->nullable();
            $t->foreign(['tenant_id', 'category_id'])->references(['tenant_id', 'id'])->on('item_categories');
            $t->unsignedBigInteger('preferred_supplier_id')->nullable();
            $t->foreign(['tenant_id', 'preferred_supplier_id'])->references(['tenant_id', 'id'])->on('contacts');
            $t->unsignedBigInteger('reorder_target_qty_milli')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $t) {
            $t->dropForeign(['tenant_id', 'category_id']);
            $t->dropForeign(['tenant_id', 'preferred_supplier_id']);
            $t->dropColumn(['category_id', 'preferred_supplier_id', 'reorder_target_qty_milli']);
        });
        Schema::dropIfExists('item_categories');
    }
};
