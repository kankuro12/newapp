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
        Schema::create('master_import_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->unique(['tenant_id', 'id']);
            $table->foreignId('created_by')->constrained('users');
            $table->string('resource', 20);
            $table->unsignedInteger('created_count');
            $table->unsignedInteger('updated_count');
            $table->unsignedInteger('category_count');
            $table->timestamp('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('master_import_batches');
    }
};
