<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private function reference(Blueprint $table, string $column, string $target, bool $nullable = false): void
    {
        $field = $table->unsignedBigInteger($column);
        if ($nullable) {
            $field->nullable();
        }
        $table->foreign(['tenant_id', $column])->references(['tenant_id', 'id'])->on($target);
    }

    public function up(): void
    {
        Schema::table('business_workflows', function (Blueprint $table) {
            $table->boolean('fulfilment_vat_recoverable')->nullable();
        });
        Schema::create('workflow_fulfilments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants');
            $table->unique(['tenant_id', 'id']);
            $this->reference($table, 'workflow_id', 'business_workflows');
            $this->reference($table, 'source_id', 'workflow_fulfilments', true);
            $table->string('kind', 30);
            $table->unsignedInteger('sequence');
            $table->unique(['tenant_id', 'kind', 'sequence']);
            $table->string('status', 20)->default('posted');
            $table->unsignedInteger('version')->default(1);
            $table->unsignedInteger('business_date_bs');
            $table->boolean('vat_recoverable')->default(false);
            $table->bigInteger('inventory_value_paisa');
            $table->bigInteger('clearing_value_paisa');
            $table->string('reference', 150)->nullable();
            $table->string('notes', 1000)->nullable();
            $this->reference($table, 'journal_id', 'journal_entries', true);
            $this->reference($table, 'reversal_journal_id', 'journal_entries', true);
            $table->unsignedInteger('cancellation_date_bs')->nullable();
            $table->string('cancellation_reason', 500)->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('cancelled_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->index(['tenant_id', 'workflow_id', 'status']);
        });
        Schema::create('workflow_fulfilment_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants');
            $table->unique(['tenant_id', 'id']);
            $this->reference($table, 'fulfilment_id', 'workflow_fulfilments');
            $this->reference($table, 'source_line_id', 'workflow_fulfilment_lines', true);
            $this->reference($table, 'item_id', 'items');
            $table->unsignedInteger('position');
            $table->bigInteger('qty_milli');
            $table->bigInteger('inventory_value_paisa');
            $table->bigInteger('clearing_value_paisa');
            $table->json('item_snapshot');
            $table->unique(['tenant_id', 'fulfilment_id', 'position'], 'fulfilment_line_position_unique');
        });
        Schema::table('stock_movements', function (Blueprint $table) {
            $this->reference($table, 'fulfilment_line_id', 'workflow_fulfilment_lines', true);
            $table->unique(['tenant_id', 'fulfilment_line_id', 'source_event'], 'movement_fulfilment_line_unique');
        });
        foreach (DB::table('tenants')->orderBy('id')->cursor() as $tenant) {
            foreach ([['1350', 'Delivered awaiting bill', 'asset', 'dr', 'delivered_unbilled'], ['2050', 'Received awaiting bill', 'liability', 'cr', 'received_unbilled']] as [$code, $name, $category, $side, $key]) {
                if (DB::table('accounts')->where('tenant_id', $tenant->id)->where('system_key', $key)->exists()) {
                    continue;
                }
                $available = $code;
                $suffix = 0;
                while (DB::table('accounts')->where('tenant_id', $tenant->id)->where('code', $available)->exists()) {
                    $available = $code.'-'.(++$suffix);
                }
                DB::table('accounts')->insert(['tenant_id' => $tenant->id, 'code' => $available, 'name' => $name, 'category' => $category, 'normal_side' => $side, 'system_key' => $key, 'is_money' => false, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        if (DB::table('workflow_fulfilments')->exists()) {
            throw new RuntimeException('Preserve fulfilment history; rollback is unavailable after actions exist.');
        }
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropForeign(['tenant_id', 'fulfilment_line_id']);
            $table->dropUnique('movement_fulfilment_line_unique');
            $table->dropColumn('fulfilment_line_id');
        });
        Schema::dropIfExists('workflow_fulfilment_lines');
        Schema::dropIfExists('workflow_fulfilments');
        Schema::table('business_workflows', fn (Blueprint $table) => $table->dropColumn('fulfilment_vat_recoverable'));
        DB::table('accounts')->whereIn('system_key', ['delivered_unbilled', 'received_unbilled'])->whereNotIn('id', DB::table('journal_lines')->select('account_id'))->delete();
    }
};
