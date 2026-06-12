<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('v2_server_machine', function (Blueprint $table): void {
            if (!Schema::hasColumn('v2_server_machine', 'webproxy_enabled')) {
                $table->boolean('webproxy_enabled')->default(false)->after('subproxy_enabled')->index();
            }
            if (!Schema::hasColumn('v2_server_machine', 'webproxy_path_prefix')) {
                $table->string('webproxy_path_prefix', 255)->nullable()->after('webproxy_enabled');
            }
        });
    }

    public function down(): void
    {
        Schema::table('v2_server_machine', function (Blueprint $table): void {
            foreach (['webproxy_path_prefix', 'webproxy_enabled'] as $column) {
                if (Schema::hasColumn('v2_server_machine', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
