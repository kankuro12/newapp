<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_workflows', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->unique(['tenant_id', 'id']);
            $t->string('kind', 20);
            $t->string('status', 20);
            $t->unsignedInteger('sequence');
            $t->unique(['tenant_id', 'kind', 'sequence']);
            $t->unsignedInteger('version')->default(1);
            $t->unsignedBigInteger('contact_id');
            $t->foreign(['tenant_id', 'contact_id'])->references(['tenant_id', 'id'])->on('contacts');
            $t->unsignedInteger('business_date_bs');
            $t->unsignedInteger('valid_until_bs')->nullable();
            $t->unsignedInteger('due_date_bs')->nullable();
            $t->string('niche', 30)->default('general');
            $t->string('title', 150)->nullable();
            $t->string('reference', 150)->nullable();
            $t->text('specifications')->nullable();
            $t->string('notes', 1000)->nullable();
            $t->string('cancellation_reason', 500)->nullable();
            $t->json('bill_input');
            $t->json('lines');
            $t->json('party_snapshot');
            $t->json('business_snapshot');
            foreach (['subtotal_paisa', 'line_discount_paisa', 'invoice_discount_paisa', 'tax_paisa', 'total_paisa'] as $field) {
                $t->bigInteger($field);
            }
            $t->unsignedBigInteger('source_id')->nullable();
            $t->foreign(['tenant_id', 'source_id'])->references(['tenant_id', 'id'])->on('business_workflows');
            $t->unique(['tenant_id', 'source_id']);
            $t->unsignedBigInteger('document_id')->nullable();
            $t->foreign(['tenant_id', 'document_id'])->references(['tenant_id', 'id'])->on('documents');
            $t->unique(['tenant_id', 'document_id']);
            $t->foreignId('created_by')->constrained('users');
            $t->timestamps();
            $t->index(['tenant_id', 'kind', 'status', 'due_date_bs']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_workflows');
    }
};
