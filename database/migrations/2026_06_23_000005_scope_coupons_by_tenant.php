<?php

use App\Models\Coupon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('v2_coupon', function (Blueprint $table): void {
            if (!Schema::hasColumn('v2_coupon', 'scope_type')) {
                $table->string('scope_type', 16)
                    ->default(Coupon::SCOPE_GLOBAL)
                    ->after('limit_period')
                    ->index('v2_coupon_scope_type_idx');
            }
            if (!Schema::hasColumn('v2_coupon', 'site_id')) {
                $table->unsignedInteger('site_id')
                    ->nullable()
                    ->after('scope_type')
                    ->index('v2_coupon_site_id_idx');
            }
            if (!Schema::hasColumn('v2_coupon', 'agent_user_id')) {
                $table->unsignedInteger('agent_user_id')
                    ->nullable()
                    ->after('site_id')
                    ->index('v2_coupon_agent_user_id_idx');
            }
            if (!Schema::hasColumn('v2_coupon', 'agent_domain_id')) {
                $table->unsignedInteger('agent_domain_id')
                    ->nullable()
                    ->after('agent_user_id')
                    ->index('v2_coupon_agent_domain_id_idx');
            }
        });
    }

    public function down(): void
    {
        Schema::table('v2_coupon', function (Blueprint $table): void {
            foreach ([
                'v2_coupon_scope_type_idx',
                'v2_coupon_site_id_idx',
                'v2_coupon_agent_user_id_idx',
                'v2_coupon_agent_domain_id_idx',
            ] as $index) {
                try {
                    $table->dropIndex($index);
                } catch (Throwable) {
                    // Some upgraded databases may not have every index if a partial migration was repaired manually.
                }
            }

            foreach (['scope_type', 'site_id', 'agent_user_id', 'agent_domain_id'] as $column) {
                if (Schema::hasColumn('v2_coupon', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
