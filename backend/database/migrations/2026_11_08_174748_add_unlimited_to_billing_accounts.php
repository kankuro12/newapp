<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Platform-granted test/demo accounts: every business type, no business limit, no expiry.
     */
    public function up(): void
    {
        Schema::table('billing_accounts', function (Blueprint $table) {
            $table->boolean('unlimited')->default(false)->after('legacy_access');
        });
    }

    public function down(): void
    {
        Schema::table('billing_accounts', function (Blueprint $table) {
            $table->dropColumn('unlimited');
        });
    }
};
