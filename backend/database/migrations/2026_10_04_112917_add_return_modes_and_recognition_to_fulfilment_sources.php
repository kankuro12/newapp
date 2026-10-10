<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workflow_return_allocations', fn (Blueprint $table) => $table->enum('return_mode', ['unfulfilled', 'transit', 'completed'])->default('completed'));
        DB::table('workflow_return_allocations')->whereNull('dispatch_allocation_id')->update(['return_mode' => 'unfulfilled']);
        Schema::table('workflow_dispatch_allocations', fn (Blueprint $table) => $table->bigInteger('recognition_sales_base_paisa')->nullable());
        $confirmed = DB::table('workflow_fulfilment_lines')->whereIn('fulfilment_id', DB::table('workflow_fulfilments')->where('handover_confirmed', true)->select('id'))->select('id');
        DB::table('workflow_dispatch_allocations')->whereIn('fulfilment_line_id', $confirmed)->update(['recognition_sales_base_paisa' => DB::raw('sales_base_paisa')]);
    }

    public function down(): void
    {
        if (DB::table('workflow_return_allocations')->where('return_mode', 'transit')->exists()) {
            throw new RuntimeException('Preserve transit return source history; rollback is unavailable.');
        }
        if (DB::table('audit_logs')->where('action', 'package.delivery.confirm')->exists()) {
            throw new RuntimeException('Preserve actual delivery allocation history; rollback is unavailable.');
        }
        Schema::table('workflow_return_allocations', fn (Blueprint $table) => $table->dropColumn('return_mode'));
        Schema::table('workflow_dispatch_allocations', fn (Blueprint $table) => $table->dropColumn('recognition_sales_base_paisa'));
    }
};
