<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (
            Schema::hasTable('v2_server_machine_release')
            && !Schema::hasColumn('v2_server_machine_release', 'binary_sha256')
        ) {
            Schema::table('v2_server_machine_release', function (Blueprint $table): void {
                $table->char('binary_sha256', 64)->nullable()->after('sha256')->comment('executable sha256');
            });
        }
    }

    public function down(): void
    {
        if (
            Schema::hasTable('v2_server_machine_release')
            && Schema::hasColumn('v2_server_machine_release', 'binary_sha256')
        ) {
            Schema::table('v2_server_machine_release', function (Blueprint $table): void {
                $table->dropColumn('binary_sha256');
            });
        }
    }
};