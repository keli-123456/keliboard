<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('v2_agent_site_setting')) {
            return;
        }

        if (!Schema::hasColumn('v2_agent_site_setting', 'customer_service_type')) {
            Schema::table('v2_agent_site_setting', function (Blueprint $table): void {
                $table->string('customer_service_type', 32)->nullable()->after('support_url');
            });
        }

        if (!Schema::hasColumn('v2_agent_site_setting', 'customer_service_id')) {
            Schema::table('v2_agent_site_setting', function (Blueprint $table): void {
                $table->string('customer_service_id', 255)->nullable()->after('customer_service_type');
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('v2_agent_site_setting')) {
            return;
        }

        if (Schema::hasColumn('v2_agent_site_setting', 'customer_service_id')) {
            Schema::table('v2_agent_site_setting', function (Blueprint $table): void {
                $table->dropColumn('customer_service_id');
            });
        }

        if (Schema::hasColumn('v2_agent_site_setting', 'customer_service_type')) {
            Schema::table('v2_agent_site_setting', function (Blueprint $table): void {
                $table->dropColumn('customer_service_type');
            });
        }
    }
};
