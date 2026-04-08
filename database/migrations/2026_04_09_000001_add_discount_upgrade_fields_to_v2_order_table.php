<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('v2_order', function (Blueprint $table): void {
            $table->integer('upgrade_quote_id')->nullable()->after('surplus_order_ids');
            $table->integer('upgrade_credit_amount')->nullable()->after('upgrade_quote_id');
            $table->json('upgrade_source_order_ids')->nullable()->after('upgrade_credit_amount');
            $table->json('upgrade_pricing_snapshot')->nullable()->after('upgrade_source_order_ids');
        });
    }

    public function down(): void
    {
        Schema::table('v2_order', function (Blueprint $table): void {
            $table->dropColumn([
                'upgrade_quote_id',
                'upgrade_credit_amount',
                'upgrade_source_order_ids',
                'upgrade_pricing_snapshot',
            ]);
        });
    }
};
