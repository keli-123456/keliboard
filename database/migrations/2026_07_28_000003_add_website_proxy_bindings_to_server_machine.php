<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('v2_server_machine', 'webproxy_bindings')) {
            Schema::table('v2_server_machine', function (Blueprint $table): void {
                $table->json('webproxy_bindings')
                    ->nullable()
                    ->after('webproxy_site_domain_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('v2_server_machine', 'webproxy_bindings')) {
            Schema::table('v2_server_machine', function (Blueprint $table): void {
                $table->dropColumn('webproxy_bindings');
            });
        }
    }
};