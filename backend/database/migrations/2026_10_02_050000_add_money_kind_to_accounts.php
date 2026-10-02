<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', fn (Blueprint $t) => $t->string('money_kind')->nullable());
        DB::table('accounts')->where('system_key', 'cash')->update(['money_kind' => 'cash']);
        DB::table('accounts')->where('system_key', 'bank_default')->update(['money_kind' => 'bank']);
    }

    public function down(): void
    {
        Schema::table('accounts', fn (Blueprint $t) => $t->dropColumn('money_kind'));
    }
};
