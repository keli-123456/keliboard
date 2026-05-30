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
            if (!Schema::hasColumn('v2_subscription_control_event', 'ip_asn')) {
                $table->integer('ip_asn')->nullable()->index()->after('cf_ray');
            }
            if (!Schema::hasColumn('v2_subscription_control_event', 'ip_prefix')) {
                $table->string('ip_prefix', 128)->nullable()->after('ip_asn');
            }
            if (!Schema::hasColumn('v2_subscription_control_event', 'ip_country')) {
                $table->string('ip_country', 8)->nullable()->index()->after('ip_prefix');
            }
            if (!Schema::hasColumn('v2_subscription_control_event', 'ip_registry')) {
                $table->string('ip_registry', 32)->nullable()->after('ip_country');
            }
            if (!Schema::hasColumn('v2_subscription_control_event', 'ip_org')) {
                $table->string('ip_org', 191)->nullable()->after('ip_registry');
            }
            if (!Schema::hasColumn('v2_subscription_control_event', 'ip_type')) {
                $table->string('ip_type', 32)->nullable()->index()->after('ip_org');
            }
            if (!Schema::hasColumn('v2_subscription_control_event', 'ip_risk_tags')) {
                $table->json('ip_risk_tags')->nullable()->after('ip_type');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('v2_subscription_control_event')) {
            return;
        }

        Schema::table('v2_subscription_control_event', function (Blueprint $table): void {
            foreach (['ip_asn', 'ip_country', 'ip_type'] as $indexedColumn) {
                if (Schema::hasColumn('v2_subscription_control_event', $indexedColumn)) {
                    $table->dropIndex([$indexedColumn]);
                }
            }

            foreach ([
                'ip_asn',
                'ip_prefix',
                'ip_country',
                'ip_registry',
                'ip_org',
                'ip_type',
                'ip_risk_tags',
            ] as $column) {
                if (Schema::hasColumn('v2_subscription_control_event', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
