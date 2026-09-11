<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('v2_payment', 'collection_policy')) {
            Schema::table('v2_payment', function (Blueprint $table): void {
                $table->json('collection_policy')->nullable();
            });
        }
        if (!Schema::hasIndex('v2_order', 'idx_order_payment_paid')) {
            Schema::table('v2_order', function (Blueprint $table): void {
                $table->index(['payment_id', 'paid_at'], 'idx_order_payment_paid');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('v2_payment', 'collection_policy')) {
            Schema::table('v2_payment', fn (Blueprint $table) => $table->dropColumn('collection_policy'));
        }
        if (Schema::hasIndex('v2_order', 'idx_order_payment_paid')) {
            Schema::table('v2_order', fn (Blueprint $table) => $table->dropIndex('idx_order_payment_paid'));
        }
    }
};
