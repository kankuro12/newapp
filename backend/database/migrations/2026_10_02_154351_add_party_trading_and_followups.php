<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $t) {
            $t->unsignedSmallInteger('sales_terms_days')->default(0);
            $t->unsignedSmallInteger('purchase_terms_days')->default(0);
            $t->unsignedBigInteger('credit_limit_paisa')->nullable();
            $t->unsignedInteger('trading_version')->default(1);
        });
        Schema::create('party_prices', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->unique(['tenant_id', 'id']);
            $t->unsignedBigInteger('contact_id');
            $t->unsignedBigInteger('item_id');
            $t->foreign(['tenant_id', 'contact_id'])->references(['tenant_id', 'id'])->on('contacts');
            $t->foreign(['tenant_id', 'item_id'])->references(['tenant_id', 'id'])->on('items');
            $t->string('channel', 10);
            $t->unsignedBigInteger('price_paisa');
            $t->string('unit_snapshot', 30);
            $t->string('pos_unit', 20);
            $t->string('item_kind', 20);
            $t->boolean('enabled')->default(true);
            $t->unsignedInteger('version')->default(1);
            $t->unique(['tenant_id', 'contact_id', 'item_id', 'channel']);
            $t->timestamps();
        });
        Schema::create('party_followups', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->unique(['tenant_id', 'id']);
            $t->unsignedBigInteger('contact_id');
            $t->foreign(['tenant_id', 'contact_id'])->references(['tenant_id', 'id'])->on('contacts');
            $t->unsignedBigInteger('document_id')->nullable();
            $t->foreign(['tenant_id', 'document_id'])->references(['tenant_id', 'id'])->on('documents');
            $t->string('title', 150);
            $t->unsignedInteger('due_date_bs');
            $t->string('channel', 20);
            $t->foreignId('assigned_to')->constrained('users');
            $t->string('status', 20)->default('open');
            $t->unsignedInteger('version')->default(1);
            $t->foreignId('created_by')->constrained('users');
            $t->timestamps();
            $t->index(['tenant_id', 'status', 'due_date_bs']);
        });
        Schema::create('party_followup_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->unsignedBigInteger('followup_id');
            $t->foreign(['tenant_id', 'followup_id'])->references(['tenant_id', 'id'])->on('party_followups');
            $t->string('action', 20);
            $t->string('notes', 1000)->nullable();
            $t->unsignedInteger('business_date_bs');
            $t->foreignId('created_by')->constrained('users');
            $t->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('party_followup_events');
        Schema::dropIfExists('party_followups');
        Schema::dropIfExists('party_prices');
        Schema::table('contacts', fn (Blueprint $t) => $t->dropColumn(['sales_terms_days', 'purchase_terms_days', 'credit_limit_paisa', 'trading_version']));
    }
};
