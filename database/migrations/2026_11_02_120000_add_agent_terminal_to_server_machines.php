<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('v2_server_machine', fn (Blueprint $t) => $t->boolean('terminal_enabled')->default(false));
    }

    public function down(): void
    {
        Schema::table('v2_server_machine', fn (Blueprint $t) => $t->dropColumn('terminal_enabled'));
    }
};
