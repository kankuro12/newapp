<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            // Cost difference posted when stock arrives to cover units already sold below zero.
            $table->unsignedBigInteger('variance_journal_id')->nullable();
            $table->foreign(['tenant_id', 'variance_journal_id'])->references(['tenant_id', 'id'])->on('journal_entries');
        });
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            // Sold-ahead stock: a negative quantity carries zero or negative value; positive stock is unchanged.
            Schema::getConnection()->statement('ALTER TABLE inventory_balances DROP CONSTRAINT inventory_balances_valid');
            Schema::getConnection()->statement('ALTER TABLE inventory_balances ADD CONSTRAINT inventory_balances_valid CHECK ((qty_milli >= 0 AND value_paisa >= 0 AND (qty_milli > 0 OR value_paisa = 0)) OR (qty_milli < 0 AND value_paisa <= 0))');
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            Schema::getConnection()->statement('ALTER TABLE inventory_balances DROP CONSTRAINT inventory_balances_valid');
            Schema::getConnection()->statement('ALTER TABLE inventory_balances ADD CONSTRAINT inventory_balances_valid CHECK (qty_milli >= 0 AND value_paisa >= 0 AND (qty_milli > 0 OR value_paisa = 0))');
        }
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropForeign(['tenant_id', 'variance_journal_id']);
            $table->dropColumn('variance_journal_id');
        });
    }
};
