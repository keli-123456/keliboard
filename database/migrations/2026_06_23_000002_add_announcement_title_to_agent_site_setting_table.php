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

        if (!Schema::hasColumn('v2_agent_site_setting', 'announcement_title')) {
            Schema::table('v2_agent_site_setting', function (Blueprint $table): void {
                $table->string('announcement_title', 120)->nullable()->after('customer_service_id');
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('v2_agent_site_setting')) {
            return;
        }

        if (Schema::hasColumn('v2_agent_site_setting', 'announcement_title')) {
            Schema::table('v2_agent_site_setting', function (Blueprint $table): void {
                $table->dropColumn('announcement_title');
            });
        }
    }
};
