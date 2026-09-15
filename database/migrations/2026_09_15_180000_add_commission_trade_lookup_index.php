<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private const INDEX = 'idx_commission_trade_lookup';

    public function up(): void
    {
        if (!Schema::hasTable('v2_commission_log')) return;
        foreach (Schema::getIndexes('v2_commission_log') as $index) {
            // An existing composite index with this leading column is sufficient.
            if (($index['columns'][0] ?? null) === 'trade_no') return;
        }
        Schema::table('v2_commission_log', function (Blueprint $table): void {
            $table->index('trade_no', self::INDEX);
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('v2_commission_log') && Schema::hasIndex('v2_commission_log', self::INDEX)) {
            Schema::table('v2_commission_log', function (Blueprint $table): void {
                $table->dropIndex(self::INDEX);
            });
        }
    }
};
