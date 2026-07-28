<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('v2_server_machine', 'webproxy_site_domain_id')) {
            Schema::table('v2_server_machine', function (Blueprint $table): void {
                $table->unsignedBigInteger('webproxy_site_domain_id')
                    ->nullable()
                    ->after('webproxy_path_prefix')
                    ->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('v2_server_machine', 'webproxy_site_domain_id')) {
            Schema::table('v2_server_machine', function (Blueprint $table): void {
                $table->dropIndex(['webproxy_site_domain_id']);
                $table->dropColumn('webproxy_site_domain_id');
            });
        }
    }
};