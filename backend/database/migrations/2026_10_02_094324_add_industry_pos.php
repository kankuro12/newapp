<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $t) {
            $t->foreignId('parent_tenant_id')->nullable()->constrained('tenants');
            $t->string('pos_profile', 20)->default('general');
        });
        Schema::table('items', function (Blueprint $t) {
            $t->string('pos_unit', 20)->default('unit');
            $t->json('pos_methods')->nullable();
            $t->json('pos_custom_units')->nullable();
            $t->unsignedSmallInteger('service_minutes')->default(30);
        });
        Schema::table('document_lines', fn (Blueprint $t) => $t->json('measurement_snapshot')->nullable());
        Schema::create('pos_resources', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->unique(['tenant_id', 'id']);
            $t->string('kind', 10);
            $t->string('name', 100);
            $t->unsignedSmallInteger('start_minute')->default(540);
            $t->unsignedSmallInteger('end_minute')->default(1200);
            $t->boolean('active')->default(true);
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
        });
        Schema::create('restaurant_orders', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->unique(['tenant_id', 'id']);
            $t->unsignedBigInteger('resource_id')->nullable();
            $t->foreign(['tenant_id', 'resource_id'])->references(['tenant_id', 'id'])->on('pos_resources');
            $t->string('kind', 20);
            $t->string('guest_name', 150)->nullable();
            $t->string('status', 20)->default('open');
            $t->unsignedInteger('version')->default(1);
            $t->foreignId('created_by')->constrained('users');
            $t->unsignedBigInteger('document_id')->nullable();
            $t->foreign(['tenant_id', 'document_id'])->references(['tenant_id', 'id'])->on('documents');
            $t->unique(['tenant_id', 'document_id']);
            $t->timestamps();
        });
        Schema::create('kitchen_tickets', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->unique(['tenant_id', 'id']);
            $t->unsignedBigInteger('order_id');
            $t->foreign(['tenant_id', 'order_id'])->references(['tenant_id', 'id'])->on('restaurant_orders');
            $t->json('lines');
            $t->string('status', 20)->default('new');
            $t->foreignId('created_by')->constrained('users');
            $t->timestamps();
        });
        Schema::create('appointments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->unique(['tenant_id', 'id']);
            $t->unsignedBigInteger('resource_id');
            $t->foreign(['tenant_id', 'resource_id'])->references(['tenant_id', 'id'])->on('pos_resources');
            $t->unsignedBigInteger('contact_id')->nullable();
            $t->foreign(['tenant_id', 'contact_id'])->references(['tenant_id', 'id'])->on('contacts');
            $t->string('client_name', 150);
            $t->string('phone', 30)->nullable();
            $t->unsignedInteger('business_date_bs');
            $t->unsignedSmallInteger('start_minute');
            $t->unsignedSmallInteger('end_minute');
            $t->json('services');
            $t->string('notes', 1000)->nullable();
            $t->string('status', 20)->default('booked');
            $t->unsignedInteger('version')->default(1);
            $t->foreignId('created_by')->constrained('users');
            $t->unsignedBigInteger('document_id')->nullable();
            $t->foreign(['tenant_id', 'document_id'])->references(['tenant_id', 'id'])->on('documents');
            $t->unique(['tenant_id', 'document_id']);
            $t->timestamps();
            $t->index(['tenant_id', 'business_date_bs', 'resource_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointments');
        Schema::dropIfExists('kitchen_tickets');
        Schema::dropIfExists('restaurant_orders');
        Schema::dropIfExists('pos_resources');
        Schema::table('document_lines', fn (Blueprint $t) => $t->dropColumn('measurement_snapshot'));
        Schema::table('items', fn (Blueprint $t) => $t->dropColumn(['pos_unit', 'pos_methods', 'pos_custom_units', 'service_minutes']));
        Schema::table('tenants', function (Blueprint $t) {
            $t->dropForeign(['parent_tenant_id']);
            $t->dropColumn(['parent_tenant_id', 'pos_profile']);
        });
    }
};
