<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private function reference(Blueprint $table, string $column, string $target, string $name, bool $nullable = false): void
    {
        $table->unsignedBigInteger($column)->nullable($nullable);
        $table->foreign(['tenant_id', $column], $name)->references(['tenant_id', 'id'])->on($target);
    }

    public function up(): void
    {
        foreach (['business_workflows', 'documents'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->enum('fulfilment_policy', ['delivery_first', 'bill_first'])->nullable());
        }
        Schema::table('workflow_fulfilments', function (Blueprint $table) {
            $table->json('party_snapshot')->nullable();
            $table->json('business_snapshot')->nullable();
            $table->boolean('handover_confirmed')->default(true);
            $this->reference($table, 'recognition_journal_id', 'journal_entries', 'b1_stage_recognition_fk', true);
        });
        Schema::table('workflow_bill_allocations', fn (Blueprint $table) => $table->dropForeign(['tenant_id', 'fulfilment_line_id']));
        Schema::table('workflow_bill_allocations', fn (Blueprint $table) => $table->unsignedBigInteger('fulfilment_line_id')->nullable()->change());
        Schema::table('workflow_bill_allocations', fn (Blueprint $table) => $table->foreign(['tenant_id', 'fulfilment_line_id'], 'b1_bill_stage_fk')->references(['tenant_id', 'id'])->on('workflow_fulfilment_lines'));

        Schema::create('workflow_dispatch_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants');
            $table->unique(['tenant_id', 'id'], 'b1_dispatch_owned_unique');
            $this->reference($table, 'workflow_id', 'business_workflows', 'b1_dispatch_workflow_fk');
            $this->reference($table, 'document_line_id', 'document_lines', 'b1_dispatch_bill_line_fk');
            $this->reference($table, 'fulfilment_line_id', 'workflow_fulfilment_lines', 'b1_dispatch_stage_line_fk');
            $table->bigInteger('qty_milli');
            $table->bigInteger('inventory_cost_paisa');
            $table->bigInteger('pending_value_paisa');
            $table->bigInteger('sales_base_paisa');
            $table->unique(['tenant_id', 'document_line_id', 'fulfilment_line_id'], 'b1_dispatch_source_unique');
            $table->index(['tenant_id', 'workflow_id'], 'b1_dispatch_workflow_index');
        });
        Schema::create('workflow_return_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants');
            $table->unique(['tenant_id', 'id'], 'b1_return_owned_unique');
            $this->reference($table, 'return_document_line_id', 'document_lines', 'b1_return_line_fk');
            $this->reference($table, 'document_line_id', 'document_lines', 'b1_return_original_line_fk');
            $this->reference($table, 'dispatch_allocation_id', 'workflow_dispatch_allocations', 'b1_return_dispatch_fk', true);
            $table->bigInteger('qty_milli');
            $table->bigInteger('inventory_cost_paisa');
            $table->bigInteger('pending_value_paisa');
            $table->bigInteger('sales_base_paisa');
            $table->unique(['tenant_id', 'return_document_line_id'], 'b1_return_document_line_unique');
        });
        Schema::create('workflow_packages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants');
            $table->unique(['tenant_id', 'id'], 'b1_package_owned_unique');
            $this->reference($table, 'workflow_id', 'business_workflows', 'b1_package_workflow_fk');
            $this->reference($table, 'fulfilment_id', 'workflow_fulfilments', 'b1_package_stage_fk', true);
            $this->reference($table, 'delivery_journal_id', 'journal_entries', 'b1_package_delivery_fk', true);
            $table->unsignedInteger('sequence');
            $table->unique(['tenant_id', 'sequence'], 'b1_package_sequence_unique');
            $table->enum('status', ['packed', 'shipped', 'delivered', 'cancelled'])->default('packed');
            $table->unsignedInteger('version')->default(1);
            $table->unsignedInteger('business_date_bs');
            $table->unsignedInteger('shipped_date_bs')->nullable();
            $table->unsignedInteger('delivered_date_bs')->nullable();
            $table->unsignedInteger('cancellation_date_bs')->nullable();
            $table->string('reference', 150)->nullable();
            $table->string('carrier', 100)->nullable();
            $table->string('tracking_reference', 150)->nullable();
            $table->string('notes', 1000)->nullable();
            $table->string('cancellation_reason', 500)->nullable();
            $table->json('party_snapshot');
            $table->json('business_snapshot');
            $table->foreignId('created_by')->constrained('users');
            foreach (['shipped_by', 'delivered_by', 'cancelled_by'] as $column) {
                $table->foreignId($column)->nullable()->constrained('users');
            }
            $table->timestamps();
            $table->index(['tenant_id', 'workflow_id', 'status'], 'b1_package_workflow_index');
        });
        Schema::create('workflow_package_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants');
            $table->unique(['tenant_id', 'id'], 'b1_package_line_owned_unique');
            $this->reference($table, 'package_id', 'workflow_packages', 'b1_package_line_header_fk');
            $this->reference($table, 'document_line_id', 'document_lines', 'b1_package_line_bill_fk');
            $table->bigInteger('qty_milli');
            $table->json('item_snapshot');
            $table->unique(['tenant_id', 'package_id', 'document_line_id'], 'b1_package_line_source_unique');
        });

        DB::table('business_workflows')->whereIn('id', DB::table('workflow_fulfilments')->select('workflow_id'))->update(['fulfilment_policy' => 'delivery_first']);
        DB::table('documents')->whereNotNull('workflow_id')->update(['fulfilment_policy' => 'delivery_first']);
        DB::table('business_workflows')->whereIn('id', DB::table('documents')->whereNotNull('workflow_id')->select('workflow_id'))->update(['fulfilment_policy' => 'delivery_first']);
        foreach (DB::table('tenants')->orderBy('id')->cursor() as $tenant) {
            foreach ([['1355', 'Billed awaiting receipt', 'asset', 'dr', 'billed_unreceived'], ['1360', 'Goods in transit', 'asset', 'dr', 'goods_in_transit'], ['2060', 'Sales billed awaiting fulfilment', 'liability', 'cr', 'sales_unfulfilled']] as [$code, $name, $category, $side, $key]) {
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
        foreach (['workflow_fulfilments', 'workflow_dispatch_allocations', 'workflow_return_allocations', 'workflow_packages'] as $name) {
            if (DB::table($name)->exists()) {
                throw new RuntimeException('Preserve fulfilment source history; bill-first rollback is unavailable after actions exist.');
            }
        }
        if (DB::table('business_workflows')->where('fulfilment_policy', 'bill_first')->exists() || DB::table('documents')->where('fulfilment_policy', 'bill_first')->exists()) {
            throw new RuntimeException('Preserve bill-first history; rollback is unavailable after policy is used.');
        }
        Schema::dropIfExists('workflow_package_lines');
        Schema::dropIfExists('workflow_packages');
        Schema::dropIfExists('workflow_return_allocations');
        Schema::dropIfExists('workflow_dispatch_allocations');
        Schema::table('workflow_bill_allocations', fn (Blueprint $table) => $table->dropForeign('b1_bill_stage_fk'));
        Schema::table('workflow_bill_allocations', fn (Blueprint $table) => $table->unsignedBigInteger('fulfilment_line_id')->nullable(false)->change());
        Schema::table('workflow_bill_allocations', fn (Blueprint $table) => $table->foreign(['tenant_id', 'fulfilment_line_id'])->references(['tenant_id', 'id'])->on('workflow_fulfilment_lines'));
        Schema::table('workflow_fulfilments', function (Blueprint $table) {
            $table->dropForeign('b1_stage_recognition_fk');
            $table->dropColumn(['party_snapshot', 'business_snapshot', 'handover_confirmed', 'recognition_journal_id']);
        });
        foreach (['business_workflows', 'documents'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn('fulfilment_policy'));
        }
        DB::table('accounts')->whereIn('system_key', ['billed_unreceived', 'goods_in_transit', 'sales_unfulfilled'])->whereNotIn('id', DB::table('journal_lines')->select('account_id'))->delete();
    }
};
