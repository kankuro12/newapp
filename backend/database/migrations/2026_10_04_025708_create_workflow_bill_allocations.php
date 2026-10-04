<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->unsignedBigInteger('workflow_id')->nullable();
            $table->foreign(['tenant_id', 'workflow_id'])->references(['tenant_id', 'id'])->on('business_workflows');
            $table->unsignedInteger('workflow_version_at_post')->nullable();
            $table->json('source_order_snapshot')->nullable();
        });
        Schema::table('workflow_fulfilments', fn (Blueprint $table) => $table->unsignedInteger('workflow_version_at_post')->nullable());
        Schema::create('workflow_bill_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants');
            $table->unique(['tenant_id', 'id']);
            foreach (['workflow_id' => 'business_workflows', 'document_line_id' => 'document_lines', 'fulfilment_line_id' => 'workflow_fulfilment_lines'] as $column => $target) {
                $table->unsignedBigInteger($column);
                $table->foreign(['tenant_id', $column])->references(['tenant_id', 'id'])->on($target);
            }
            $table->unsignedInteger('position');
            $table->bigInteger('qty_milli');
            $table->bigInteger('clearing_value_paisa');
            $table->unique(['tenant_id', 'document_line_id', 'fulfilment_line_id'], 'bill_stage_allocation_unique');
            $table->index(['tenant_id', 'workflow_id', 'position'], 'bill_stage_position_index');
        });
    }

    public function down(): void
    {
        if (DB::table('workflow_bill_allocations')->exists()) {
            throw new RuntimeException('Preserve staged bill history; rollback is unavailable after allocations exist.');
        }
        Schema::dropIfExists('workflow_bill_allocations');
        Schema::table('documents', function (Blueprint $table) {
            $table->dropForeign(['tenant_id', 'workflow_id']);
            $table->dropColumn(['workflow_id', 'workflow_version_at_post', 'source_order_snapshot']);
        });
        Schema::table('workflow_fulfilments', fn (Blueprint $table) => $table->dropColumn('workflow_version_at_post'));
    }
};
