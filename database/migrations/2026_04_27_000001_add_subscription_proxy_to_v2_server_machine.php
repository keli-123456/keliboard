<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('v2_server_machine', function (Blueprint $table): void {
            if (!Schema::hasColumn('v2_server_machine', 'subproxy_enabled')) {
                $table->boolean('subproxy_enabled')->default(false)->after('is_active')->index();
            }
            if (!Schema::hasColumn('v2_server_machine', 'subproxy_https_port')) {
                $table->unsignedSmallInteger('subproxy_https_port')->nullable()->after('subproxy_enabled');
            }
            if (!Schema::hasColumn('v2_server_machine', 'subproxy_http_port')) {
                $table->unsignedSmallInteger('subproxy_http_port')->nullable()->after('subproxy_https_port');
            }
            if (!Schema::hasColumn('v2_server_machine', 'subproxy_cert_domain')) {
                $table->string('subproxy_cert_domain', 255)->nullable()->after('subproxy_http_port');
            }
            if (!Schema::hasColumn('v2_server_machine', 'subproxy_cert_state')) {
                $table->json('subproxy_cert_state')->nullable()->after('subproxy_cert_domain');
            }
        });
    }

    public function down(): void
    {
        Schema::table('v2_server_machine', function (Blueprint $table): void {
            foreach (['subproxy_cert_state', 'subproxy_cert_domain', 'subproxy_http_port', 'subproxy_https_port', 'subproxy_enabled'] as $column) {
                if (Schema::hasColumn('v2_server_machine', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
