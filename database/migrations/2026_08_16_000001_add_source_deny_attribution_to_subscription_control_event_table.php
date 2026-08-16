<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('v2_subscription_control_event')) {
            return;
        }

        Schema::table('v2_subscription_control_event', function (Blueprint $table): void {
            if (!Schema::hasColumn('v2_subscription_control_event', 'source_ip_deny_match_type')) {
                $table->string('source_ip_deny_match_type', 32)->nullable()->index()->after('ip_risk_tags');
            }
            if (!Schema::hasColumn('v2_subscription_control_event', 'source_ip_deny_match')) {
                $table->string('source_ip_deny_match', 191)->nullable()->after('source_ip_deny_match_type');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('v2_subscription_control_event')) {
            return;
        }

        Schema::table('v2_subscription_control_event', function (Blueprint $table): void {
            if (Schema::hasColumn('v2_subscription_control_event', 'source_ip_deny_match_type')) {
                $table->dropIndex(['source_ip_deny_match_type']);
            }
            foreach (['source_ip_deny_match_type', 'source_ip_deny_match'] as $column) {
                if (Schema::hasColumn('v2_subscription_control_event', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};