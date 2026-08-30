<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private const PAID_INDEX = 'idx_order_retention_paid';
    private const USER_INDEX = 'idx_order_retention_user';

    public function up(): void
    {
        if (!Schema::hasTable('v2_order')) {
            return;
        }

        if (!Schema::hasIndex('v2_order', self::PAID_INDEX)) {
            Schema::table('v2_order', function (Blueprint $table): void {
                $table->index(['status', 'type', 'paid_at'], self::PAID_INDEX);
            });
        }
        if (!Schema::hasIndex('v2_order', self::USER_INDEX)) {
            Schema::table('v2_order', function (Blueprint $table): void {
                $table->index(['status', 'user_id'], self::USER_INDEX);
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('v2_order')) {
            return;
        }

        foreach ([self::PAID_INDEX, self::USER_INDEX] as $index) {
            if (Schema::hasIndex('v2_order', $index)) {
                Schema::table('v2_order', function (Blueprint $table) use ($index): void {
                    $table->dropIndex($index);
                });
            }
        }
    }
};
