<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('v2_ticket', 'withdrawal')) {
            Schema::table('v2_ticket', fn (Blueprint $table) => $table->json('withdrawal')->nullable());
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('v2_ticket', 'withdrawal')) {
            Schema::table('v2_ticket', fn (Blueprint $table) => $table->dropColumn('withdrawal'));
        }
    }
};
