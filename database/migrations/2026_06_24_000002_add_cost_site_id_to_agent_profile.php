<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('v2_agent_profile')) {
            return;
        }

        if (!Schema::hasColumn('v2_agent_profile', 'cost_site_id')) {
            Schema::table('v2_agent_profile', function (Blueprint $table): void {
                $table->unsignedInteger('cost_site_id')->nullable()->after('user_id')->index();
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('v2_agent_profile') || !Schema::hasColumn('v2_agent_profile', 'cost_site_id')) {
            return;
        }

        Schema::table('v2_agent_profile', function (Blueprint $table): void {
            $table->dropColumn('cost_site_id');
        });
    }
};
