<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->text('body');
            $table->json('channels');
            $table->string('audience', 20);
            $table->string('status', 20)->default('draft');
            $table->dateTime('scheduled_at')->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->unsignedInteger('recipient_count')->default(0);
            $table->unsignedBigInteger('version')->default(1);
            $table->foreignId('created_by')->constrained('super_admins');
            $table->timestamps();
            $table->index(['status', 'scheduled_at']);
        });
        Schema::table('message_deliveries', function (Blueprint $table) {
            $table->foreignId('campaign_id')->nullable()->constrained('marketing_campaigns');
        });
    }

    public function down(): void
    {
        Schema::table('message_deliveries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('campaign_id');
        });
        Schema::dropIfExists('marketing_campaigns');
    }
};
