<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $t) {
            $t->boolean('is_employee')->default(false);
            $t->boolean('is_rent')->default(false);
        });
        Schema::create('recurring_expenses', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->unique(['tenant_id', 'id']);
            $t->string('kind', 20);
            $t->string('label', 150);
            $t->unsignedBigInteger('contact_id');
            $t->unsignedBigInteger('expense_category_id');
            $t->foreign(['tenant_id', 'contact_id'])->references(['tenant_id', 'id'])->on('contacts');
            $t->foreign(['tenant_id', 'expense_category_id'])->references(['tenant_id', 'id'])->on('expense_categories');
            $t->bigInteger('amount_paisa');
            $t->unsignedInteger('first_date_bs');
            $t->unsignedInteger('next_date_bs');
            $t->unsignedTinyInteger('monthly_day');
            $t->boolean('auto_generate')->default(false);
            $t->boolean('enabled')->default(true);
            $t->unsignedInteger('version')->default(1);
            $t->foreignId('authorized_by')->constrained('users');
            $t->text('last_error')->nullable();
            $t->timestamps();
            $t->index(['enabled', 'auto_generate', 'next_date_bs']);
        });
        Schema::create('recurring_expense_occurrences', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->unique(['tenant_id', 'id']);
            $t->unsignedBigInteger('recurring_expense_id');
            $t->unsignedBigInteger('document_id');
            $t->unsignedInteger('period_bs');
            $t->foreign(['tenant_id', 'recurring_expense_id'], 'occurrence_rule_foreign')->references(['tenant_id', 'id'])->on('recurring_expenses');
            $t->foreign(['tenant_id', 'document_id'])->references(['tenant_id', 'id'])->on('documents');
            $t->unique(['tenant_id', 'recurring_expense_id', 'period_bs'], 'recurring_month_unique');
            $t->unique(['tenant_id', 'document_id']);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recurring_expense_occurrences');
        Schema::dropIfExists('recurring_expenses');
        Schema::table('contacts', fn (Blueprint $t) => $t->dropColumn(['is_employee', 'is_rent']));
    }
};
