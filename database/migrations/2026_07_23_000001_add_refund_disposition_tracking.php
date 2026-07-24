<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('v2_order')) {
            Schema::table('v2_order', function (Blueprint $table): void {
                if (!Schema::hasColumn('v2_order', 'commission_reversed_amount')) {
                    $table->integer('commission_reversed_amount')->default(0)->after('actual_commission_balance');
                }
                if (!Schema::hasColumn('v2_order', 'refund_disposed_at')) {
                    $table->integer('refund_disposed_at')->nullable()->index()->after('refund_amount');
                }
                if (!Schema::hasColumn('v2_order', 'refund_disposed_by')) {
                    $table->integer('refund_disposed_by')->nullable()->after('refund_disposed_at');
                }
            });
        }

        if (Schema::hasTable('v2_commission_log')) {
            Schema::table('v2_commission_log', function (Blueprint $table): void {
                if (!Schema::hasColumn('v2_commission_log', 'credited_to')) {
                    $table->string('credited_to', 32)->nullable()->after('get_amount');
                }
                if (!Schema::hasColumn('v2_commission_log', 'reversed_at')) {
                    $table->integer('reversed_at')->nullable()->index()->after('credited_to');
                }
                if (!Schema::hasColumn('v2_commission_log', 'reversed_by_admin_id')) {
                    $table->integer('reversed_by_admin_id')->nullable()->after('reversed_at');
                }
            });
        }

        if (Schema::hasTable('v2_user') && !Schema::hasColumn('v2_user', 'banned_reason')) {
            Schema::table('v2_user', function (Blueprint $table): void {
                $table->string('banned_reason', 255)->nullable()->after('banned');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('v2_order')) {
            Schema::table('v2_order', function (Blueprint $table): void {
                $columns = array_values(array_filter([
                    Schema::hasColumn('v2_order', 'commission_reversed_amount') ? 'commission_reversed_amount' : null,
                    Schema::hasColumn('v2_order', 'refund_disposed_at') ? 'refund_disposed_at' : null,
                    Schema::hasColumn('v2_order', 'refund_disposed_by') ? 'refund_disposed_by' : null,
                ]));
                if ($columns !== []) {
                    $table->dropColumn($columns);
                }
            });
        }

        if (Schema::hasTable('v2_commission_log')) {
            Schema::table('v2_commission_log', function (Blueprint $table): void {
                $columns = array_values(array_filter([
                    Schema::hasColumn('v2_commission_log', 'credited_to') ? 'credited_to' : null,
                    Schema::hasColumn('v2_commission_log', 'reversed_at') ? 'reversed_at' : null,
                    Schema::hasColumn('v2_commission_log', 'reversed_by_admin_id') ? 'reversed_by_admin_id' : null,
                ]));
                if ($columns !== []) {
                    $table->dropColumn($columns);
                }
            });
        }
    }
};
