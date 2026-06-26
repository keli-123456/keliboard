<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (
            !Schema::hasTable('v2_order')
            || !Schema::hasColumn('v2_order', 'commission_status')
            || !Schema::hasColumn('v2_order', 'commission_balance')
            || !Schema::hasColumn('v2_order', 'invite_user_id')
        ) {
            return;
        }

        DB::table('v2_order')
            ->where('status', 2)
            ->where(function ($query): void {
                $query->whereIn('commission_status', [0, 1])
                    ->orWhereNull('commission_status');
            })
            ->where(function ($query): void {
                $query->where('commission_balance', '>', 0)
                    ->orWhereNotNull('invite_user_id');
            })
            ->update(['commission_status' => 3]);
    }

    public function down(): void
    {
        // Historical invalidation is intentionally not reverted.
    }
};
