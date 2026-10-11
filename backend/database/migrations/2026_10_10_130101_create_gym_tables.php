<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gym_plans', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->unique(['tenant_id', 'id']);
            $t->string('name', 150);
            // Service item billed on the membership invoice; the plan fee is its exact price.
            $t->unsignedBigInteger('item_id');
            $t->foreign(['tenant_id', 'item_id'])->references(['tenant_id', 'id'])->on('items');
            $t->unsignedBigInteger('fee_paisa');
            $t->unsignedSmallInteger('duration_days');
            $t->unsignedSmallInteger('visit_limit')->nullable();
            $t->boolean('class_access')->default(true);
            $t->boolean('active')->default(true);
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['tenant_id', 'name']);
        });
        Schema::create('gym_members', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->unique(['tenant_id', 'id']);
            $t->unsignedBigInteger('contact_id');
            $t->foreign(['tenant_id', 'contact_id'])->references(['tenant_id', 'id'])->on('contacts');
            $t->unique(['tenant_id', 'contact_id']);
            $t->string('member_code', 30);
            $t->unique(['tenant_id', 'member_code']);
            $t->unsignedInteger('joined_bs');
            $t->string('notes', 500)->nullable();
            $t->boolean('active')->default(true);
            $t->unsignedInteger('version')->default(1);
            $t->foreignId('created_by')->constrained('users');
            $t->timestamps();
        });
        Schema::create('gym_memberships', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->unique(['tenant_id', 'id']);
            $t->unsignedBigInteger('member_id');
            $t->foreign(['tenant_id', 'member_id'])->references(['tenant_id', 'id'])->on('gym_members');
            $t->unsignedBigInteger('plan_id');
            $t->foreign(['tenant_id', 'plan_id'])->references(['tenant_id', 'id'])->on('gym_plans');
            // Frozen plan terms: editing the plan never reprices or reshapes a sold membership.
            $t->json('plan_snapshot');
            $t->unsignedInteger('start_bs');
            $t->unsignedInteger('end_bs');
            $t->unsignedSmallInteger('visits_used')->default(0);
            $t->string('status', 20)->default('active');
            $t->unsignedBigInteger('document_id')->nullable();
            $t->foreign(['tenant_id', 'document_id'])->references(['tenant_id', 'id'])->on('documents');
            $t->unique(['tenant_id', 'document_id']);
            $t->unsignedInteger('version')->default(1);
            $t->foreignId('created_by')->constrained('users');
            $t->timestamps();
            $t->index(['tenant_id', 'member_id', 'end_bs']);
        });
        Schema::create('gym_checkins', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->unsignedBigInteger('member_id');
            $t->foreign(['tenant_id', 'member_id'])->references(['tenant_id', 'id'])->on('gym_members');
            $t->unsignedBigInteger('membership_id');
            $t->foreign(['tenant_id', 'membership_id'])->references(['tenant_id', 'id'])->on('gym_memberships');
            $t->unsignedInteger('business_date_bs');
            $t->unsignedSmallInteger('minute');
            $t->foreignId('created_by')->constrained('users');
            $t->timestamp('created_at');
            // One counted visit per membership per business day.
            $t->unique(['tenant_id', 'membership_id', 'business_date_bs']);
            $t->index(['tenant_id', 'business_date_bs']);
        });
        Schema::create('gym_classes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->unique(['tenant_id', 'id']);
            $t->string('name', 150);
            $t->string('trainer_name', 150)->nullable();
            $t->unsignedInteger('business_date_bs');
            $t->unsignedSmallInteger('start_minute');
            $t->unsignedSmallInteger('duration_minutes');
            $t->unsignedSmallInteger('capacity');
            $t->unsignedSmallInteger('booked')->default(0);
            $t->string('status', 20)->default('scheduled');
            $t->unsignedInteger('version')->default(1);
            $t->foreignId('created_by')->constrained('users');
            $t->timestamps();
            $t->index(['tenant_id', 'business_date_bs']);
        });
        Schema::create('gym_bookings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->unsignedBigInteger('class_id');
            $t->foreign(['tenant_id', 'class_id'])->references(['tenant_id', 'id'])->on('gym_classes');
            $t->unsignedBigInteger('member_id');
            $t->foreign(['tenant_id', 'member_id'])->references(['tenant_id', 'id'])->on('gym_members');
            $t->string('status', 20)->default('booked');
            $t->foreignId('created_by')->constrained('users');
            $t->timestamps();
            $t->unique(['tenant_id', 'class_id', 'member_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gym_bookings');
        Schema::dropIfExists('gym_classes');
        Schema::dropIfExists('gym_checkins');
        Schema::dropIfExists('gym_memberships');
        Schema::dropIfExists('gym_members');
        Schema::dropIfExists('gym_plans');
    }
};
