<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->string('locale', 2)->default('en');
            $t->timestamp('disabled_at')->nullable();
        });
        Schema::create('super_admins', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('email')->unique();
            $t->string('password');
            $t->rememberToken();
            $t->timestamps();
        });
        Schema::create('tenants', function (Blueprint $t) {
            $t->id();
            $t->string('slug')->unique();
            $t->string('name');
            $t->text('address')->nullable();
            $t->string('phone')->nullable();
            $t->string('pan')->nullable();
            $t->string('default_locale', 2)->default('en');
            $t->boolean('tax_recording_enabled')->default(false);
            $t->unsignedInteger('default_tax_bps')->default(0);
            $t->string('access_status')->default('trial');
            $t->timestamp('trial_ends_at')->nullable();
            $t->timestamp('access_until')->nullable();
            $t->unsignedInteger('closed_through_bs')->nullable();
            $t->unsignedInteger('last_stock_date_bs')->nullable();
            $t->unsignedInteger('opening_date_bs')->nullable();
            $t->timestamp('opening_finalized_at')->nullable();
            $t->unsignedBigInteger('data_version')->default(1);
            $t->timestamps();
        });
        Schema::create('tenant_user', function (Blueprint $t) {
            $t->foreignId('tenant_id')->constrained();
            $t->foreignId('user_id')->constrained();
            $t->string('role');
            $t->boolean('active')->default(true);
            $t->unique(['tenant_id', 'user_id']);
        });
        Schema::create('contacts', function (Blueprint $t) {
            $this->owned($t);
            $t->string('name');
            $t->string('phone')->nullable();
            $t->string('email')->nullable();
            $t->text('address')->nullable();
            $t->string('pan')->nullable();
            $t->boolean('is_customer')->default(true);
            $t->boolean('is_supplier')->default(false);
            $t->boolean('is_system')->default(false);
            $t->timestamp('archived_at')->nullable();
            $t->timestamps();
            $t->index(['tenant_id', 'name']);
        });
        Schema::create('items', function (Blueprint $t) {
            $this->owned($t);
            $t->string('name');
            $t->string('sku')->nullable();
            $t->string('kind');
            $t->string('unit_label');
            $t->bigInteger('sale_price_paisa')->default(0);
            $t->bigInteger('last_purchase_price_paisa')->nullable();
            $t->string('default_tax_category')->default('outside_scope');
            $t->unsignedInteger('default_tax_bps')->default(0);
            $t->bigInteger('low_stock_qty_milli')->default(0);
            $t->timestamp('archived_at')->nullable();
            $t->timestamps();
            $t->unique(['tenant_id', 'sku']);
            $t->index(['tenant_id', 'name']);
        });
        Schema::create('accounts', function (Blueprint $t) {
            $this->owned($t);
            $t->string('code');
            $t->string('name');
            $t->string('category');
            $t->string('normal_side');
            $t->string('system_key')->nullable();
            $t->boolean('is_money')->default(false);
            $t->timestamp('archived_at')->nullable();
            $t->timestamps();
            $t->unique(['tenant_id', 'code']);
            $t->unique(['tenant_id', 'system_key']);
        });
        Schema::create('expense_categories', function (Blueprint $t) {
            $this->owned($t);
            $this->reference($t, 'account_id', 'accounts');
            $t->string('name');
            $t->timestamp('archived_at')->nullable();
            $t->timestamps();
            $t->unique(['tenant_id', 'name']);
        });
        Schema::create('journal_entries', function (Blueprint $t) {
            $this->owned($t);
            $t->string('source_type');
            $t->unsignedBigInteger('source_id');
            $t->string('source_event')->default('post');
            $t->unsignedInteger('business_date_bs');
            $t->string('fiscal_year_label');
            $t->text('description');
            $t->foreignId('created_by')->constrained('users');
            $this->reference($t, 'reversal_of_id', 'journal_entries', true);
            $t->string('owner_entry_kind')->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->unique(['tenant_id', 'source_type', 'source_id', 'source_event'], 'journal_source_unique');
            $t->unique(['tenant_id', 'reversal_of_id']);
            $t->index(['tenant_id', 'business_date_bs', 'id']);
        });
        Schema::create('journal_lines', function (Blueprint $t) {
            $this->owned($t);
            $this->reference($t, 'journal_entry_id', 'journal_entries');
            $this->reference($t, 'account_id', 'accounts');
            $this->reference($t, 'contact_id', 'contacts', true);
            $t->bigInteger('debit_paisa')->default(0);
            $t->bigInteger('credit_paisa')->default(0);
            $t->index(['tenant_id', 'contact_id', 'account_id']);
        });
        Schema::create('documents', function (Blueprint $t) {
            $this->owned($t);
            $t->string('type');
            $t->string('status')->default('draft');
            $t->unsignedInteger('version')->default(1);
            $t->string('number')->nullable();
            $t->string('fiscal_year_label')->nullable();
            $this->reference($t, 'contact_id', 'contacts');
            $this->reference($t, 'source_document_id', 'documents', true);
            $t->unsignedInteger('business_date_bs');
            $t->unsignedInteger('due_date_bs')->nullable();
            $t->string('supplier_bill_number')->nullable();
            $t->unsignedInteger('supplier_bill_date_bs')->nullable();
            $t->text('notes')->nullable();
            $t->text('reason')->nullable();
            $t->json('party_snapshot');
            $t->json('business_snapshot');
            foreach (['subtotal_paisa', 'line_discount_paisa', 'invoice_discount_paisa', 'tax_paisa', 'total_paisa'] as $field) {
                $t->bigInteger($field)->default(0);
            }
            $t->boolean('vat_recoverable')->default(false);
            $t->json('draft_input')->nullable();
            $t->foreignId('created_by')->constrained('users');
            $t->foreignId('posted_by')->nullable()->constrained('users');
            $t->timestamp('posted_at')->nullable();
            $this->reference($t, 'journal_id', 'journal_entries', true);
            $this->reference($t, 'reversal_journal_id', 'journal_entries', true);
            $t->foreignId('cancelled_by')->nullable()->constrained('users');
            $t->timestamp('cancelled_at')->nullable();
            $t->unsignedInteger('cancellation_date_bs')->nullable();
            $t->text('cancellation_reason')->nullable();
            $t->timestamps();
            $t->unique(['tenant_id', 'number']);
            $t->index(['tenant_id', 'type', 'business_date_bs', 'id']);
            $t->index(['tenant_id', 'contact_id', 'business_date_bs']);
        });
        Schema::create('document_lines', function (Blueprint $t) {
            $this->owned($t);
            $this->reference($t, 'document_id', 'documents');
            $this->reference($t, 'item_id', 'items', true);
            $this->reference($t, 'expense_category_id', 'expense_categories', true);
            $this->reference($t, 'source_line_id', 'document_lines', true);
            $t->unsignedInteger('position');
            $t->string('description');
            $t->string('unit_snapshot');
            $t->string('tax_category');
            $t->unsignedInteger('tax_bps')->default(0);
            foreach (['qty_milli', 'unit_price_paisa', 'gross_paisa', 'line_discount_paisa', 'invoice_discount_paisa', 'net_base_paisa', 'tax_paisa', 'total_paisa', 'inventory_cost_paisa'] as $field) {
                $t->bigInteger($field)->default(0);
            } $t->unique(['tenant_id', 'document_id', 'position']);
        });
        Schema::create('document_sequences', function (Blueprint $t) {
            $this->owned($t);
            $t->string('fiscal_year_label');
            $t->string('type');
            $t->unsignedBigInteger('next_number')->default(1);
            $t->unique(['tenant_id', 'fiscal_year_label', 'type']);
        });
        Schema::create('payments', function (Blueprint $t) {
            $this->owned($t);
            $t->string('kind');
            $t->string('status')->default('posted');
            $this->reference($t, 'contact_id', 'contacts', true);
            $this->reference($t, 'money_account_id', 'accounts');
            $this->reference($t, 'destination_account_id', 'accounts', true);
            $t->bigInteger('amount_paisa');
            $t->unsignedInteger('business_date_bs');
            $t->string('reference')->nullable();
            $t->text('notes')->nullable();
            $t->foreignId('created_by')->constrained('users');
            $this->reference($t, 'journal_id', 'journal_entries', true);
            $this->reference($t, 'reversal_journal_id', 'journal_entries', true);
            $t->foreignId('cancelled_by')->nullable()->constrained('users');
            $t->timestamp('cancelled_at')->nullable();
            $t->unsignedInteger('cancellation_date_bs')->nullable();
            $t->text('cancellation_reason')->nullable();
            $t->timestamps();
            $t->index(['tenant_id', 'contact_id', 'business_date_bs']);
        });
        Schema::create('payment_allocations', function (Blueprint $t) {
            $this->owned($t);
            $this->reference($t, 'payment_id', 'payments');
            $this->reference($t, 'document_id', 'documents');
            $t->bigInteger('amount_paisa');
            $t->unique(['tenant_id', 'payment_id', 'document_id']);
        });
        Schema::create('inventory_balances', function (Blueprint $t) {
            $this->owned($t);
            $this->reference($t, 'item_id', 'items');
            $t->bigInteger('qty_milli')->default(0);
            $t->bigInteger('value_paisa')->default(0);
            $t->unique(['tenant_id', 'item_id']);
        });
        Schema::create('stock_adjustments', function (Blueprint $t) {
            $this->owned($t);
            $this->reference($t, 'item_id', 'items');
            $t->bigInteger('qty_delta_milli');
            $t->unsignedInteger('business_date_bs');
            $t->text('reason');
            $t->string('status')->default('posted');
            $t->foreignId('created_by')->constrained('users');
            $this->reference($t, 'journal_id', 'journal_entries', true);
            $this->reference($t, 'reversal_journal_id', 'journal_entries', true);
            $t->unsignedInteger('cancellation_date_bs')->nullable();
            $t->text('cancellation_reason')->nullable();
            $t->timestamps();
        });
        Schema::create('opening_balances', function (Blueprint $t) {
            $this->owned($t);
            $this->reference($t, 'account_id', 'accounts');
            $this->reference($t, 'contact_id', 'contacts', true);
            $this->reference($t, 'item_id', 'items', true);
            $this->reference($t, 'journal_id', 'journal_entries', true);
            $t->bigInteger('debit_paisa')->default(0);
            $t->bigInteger('credit_paisa')->default(0);
            $t->bigInteger('qty_milli')->nullable();
            $t->unsignedInteger('business_date_bs');
            $t->foreignId('created_by')->constrained('users');
        });
        Schema::create('stock_movements', function (Blueprint $t) {
            $this->owned($t);
            $this->reference($t, 'item_id', 'items');
            $this->reference($t, 'document_line_id', 'document_lines', true);
            $this->reference($t, 'stock_adjustment_id', 'stock_adjustments', true);
            $this->reference($t, 'opening_balance_id', 'opening_balances', true);
            $this->reference($t, 'reversal_of_id', 'stock_movements', true);
            $t->string('source_event')->default('post');
            $t->unsignedInteger('business_date_bs');
            foreach (['qty_delta_milli', 'value_delta_paisa', 'qty_after_milli', 'value_after_paisa'] as $field) {
                $t->bigInteger($field);
            } $t->foreignId('created_by')->constrained('users');
            $t->timestamp('created_at')->useCurrent();
            $t->unique(['tenant_id', 'reversal_of_id']);
            foreach (['document_line_id', 'stock_adjustment_id', 'opening_balance_id'] as $field) {
                $t->unique(['tenant_id', $field, 'source_event'], 'movement_'.$field.'_unique');
            } $t->index(['tenant_id', 'item_id', 'business_date_bs', 'id']);
        });
        Schema::create('mutation_requests', function (Blueprint $t) {
            $this->owned($t);
            $t->uuid('mutation_uuid');
            $t->string('operation');
            $t->string('request_hash', 64);
            $t->foreignId('actor_id')->constrained('users');
            $t->string('result_type');
            $t->unsignedBigInteger('result_id');
            $t->unique(['tenant_id', 'mutation_uuid']);
        });
        Schema::create('audit_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->nullable()->constrained();
            $t->unsignedBigInteger('actor_id')->nullable();
            $t->string('actor_guard')->default('tenant');
            $t->string('action');
            $t->string('subject_type');
            $t->unsignedBigInteger('subject_id')->nullable();
            $t->json('metadata');
            $t->timestamp('created_at')->useCurrent();
            $t->index(['tenant_id', 'created_at']);
        });
        Schema::create('invitations', function (Blueprint $t) {
            $this->owned($t);
            $t->string('email');
            $t->string('role');
            $t->string('token_hash', 64)->unique();
            $t->timestamp('expires_at');
            $t->foreignId('invited_by')->constrained('users');
            $t->timestamp('accepted_at')->nullable();
            $t->timestamp('revoked_at')->nullable();
            $t->timestamps();
        });
        Schema::create('attachments', function (Blueprint $t) {
            $this->owned($t);
            $this->reference($t, 'document_id', 'documents', true);
            $this->reference($t, 'payment_id', 'payments', true);
            $t->string('storage_path');
            $t->string('original_name');
            $t->string('mime_type');
            $t->unsignedBigInteger('size_bytes');
            $t->foreignId('uploaded_by')->constrained('users');
            $t->timestamps();
        });
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            foreach (['journal_lines' => '(debit_paisa > 0 AND credit_paisa = 0) OR (credit_paisa > 0 AND debit_paisa = 0)', 'inventory_balances' => 'qty_milli >= 0 AND value_paisa >= 0 AND (qty_milli > 0 OR value_paisa = 0)', 'payments' => 'amount_paisa > 0', 'payment_allocations' => 'amount_paisa > 0', 'documents' => 'total_paisa >= 0 AND tax_paisa >= 0'] as $table => $check) {
                Schema::getConnection()->statement("ALTER TABLE $table ADD CONSTRAINT {$table}_valid CHECK ($check)");
            }
        }
    }

    private function owned(Blueprint $t): void
    {
        $t->id();
        $t->foreignId('tenant_id')->constrained('tenants');
        $t->unique(['tenant_id', 'id']);
    }

    private function reference(Blueprint $t, string $field, string $table, bool $nullable = false): void
    {
        $column = $t->unsignedBigInteger($field);
        if ($nullable) {
            $column->nullable();
        } $t->foreign(['tenant_id', $field])->references(['tenant_id', 'id'])->on($table)->restrictOnDelete();
    }

    public function down(): void
    {
        foreach (['attachments', 'invitations', 'audit_logs', 'mutation_requests', 'stock_movements', 'opening_balances', 'stock_adjustments', 'inventory_balances', 'payment_allocations', 'payments', 'document_sequences', 'document_lines', 'documents', 'journal_lines', 'journal_entries', 'expense_categories', 'accounts', 'items', 'contacts', 'tenant_user', 'tenants', 'super_admins'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('users', function (Blueprint $t) {
            $t->dropColumn(['locale', 'disabled_at']);
        });
    }
};
