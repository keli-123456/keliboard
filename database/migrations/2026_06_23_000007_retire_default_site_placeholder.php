<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('v2_site')) {
            return;
        }

        $defaultSiteIds = DB::table('v2_site')
            ->where('is_default', true)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->values()
            ->all();

        if ($defaultSiteIds === []) {
            return;
        }

        foreach ([
            'v2_user',
            'v2_order',
            'v2_notice',
            'v2_ticket',
            'v2_marketing_template',
            'v2_marketing_rule',
            'v2_marketing_dispatch_log',
            'v2_coupon',
            'v2_gift_card_template',
            'v2_gift_card_code',
            'v2_gift_card_usage',
        ] as $table) {
            $this->moveToPlatformScope($table, $defaultSiteIds);
        }

        foreach ([
            'v2_site_order_context',
            'v2_site_setting',
            'v2_site_plan_price',
            'v2_site_plan_override',
            'v2_site_payment',
            'v2_site_domain',
        ] as $table) {
            $this->deleteSiteOwnedRows($table, $defaultSiteIds);
        }

        DB::table('v2_site')
            ->whereIn('id', $defaultSiteIds)
            ->delete();
    }

    public function down(): void
    {
        // The retired placeholder only represented the platform/main site.
    }

    /**
     * @param array<int, int> $siteIds
     */
    private function moveToPlatformScope(string $table, array $siteIds): void
    {
        if (!Schema::hasTable($table) || !Schema::hasColumn($table, 'site_id')) {
            return;
        }

        if (Schema::hasColumn($table, 'scope_type')) {
            DB::table($table)
                ->whereIn('site_id', $siteIds)
                ->where('scope_type', 'site')
                ->update([
                    'scope_type' => 'global',
                    'site_id' => null,
                ]);
        }

        DB::table($table)
            ->whereIn('site_id', $siteIds)
            ->update(['site_id' => null]);
    }

    /**
     * @param array<int, int> $siteIds
     */
    private function deleteSiteOwnedRows(string $table, array $siteIds): void
    {
        if (!Schema::hasTable($table) || !Schema::hasColumn($table, 'site_id')) {
            return;
        }

        DB::table($table)
            ->whereIn('site_id', $siteIds)
            ->delete();
    }
};
