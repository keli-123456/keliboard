<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('v2_server_machine') || Schema::hasColumn('v2_server_machine', 'upgrade_state')) {
            return;
        }

        Schema::table('v2_server_machine', function (Blueprint $table): void {
            $table->json('upgrade_state')->nullable()->after('load_status');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('v2_server_machine') || !Schema::hasColumn('v2_server_machine', 'upgrade_state')) {
            return;
        }

        Schema::table('v2_server_machine', function (Blueprint $table): void {
            $table->dropColumn('upgrade_state');
        });
    }
};
