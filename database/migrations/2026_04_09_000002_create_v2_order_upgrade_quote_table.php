<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('v2_order_upgrade_quote')) {
            return;
        }

        Schema::create('v2_order_upgrade_quote', function (Blueprint $table): void {
            $table->integer('id', true);
            $table->integer('user_id');
            $table->integer('source_order_id');
            $table->integer('source_plan_id');
            $table->integer('target_plan_id');
            $table->string('target_period', 32);
            $table->integer('target_price');
            $table->integer('source_paid_basis');
            $table->decimal('time_ratio', 8, 4);
            $table->decimal('traffic_ratio', 8, 4);
            $table->decimal('base_credit_coeff', 8, 4);
            $table->decimal('usage_penalty_coeff', 8, 4);
            $table->integer('credit_cap_amount');
            $table->integer('min_pay_amount');
            $table->integer('upgrade_credit_amount');
            $table->integer('final_pay_amount');
            $table->string('token', 64)->unique('v2_order_upgrade_quote_token_unique');
            $table->string('status', 16)->default('pending');
            $table->json('snapshot')->nullable();
            $table->integer('expires_at');
            $table->integer('created_at');
            $table->integer('updated_at');

            $table->index(['user_id', 'status'], 'v2_order_upgrade_quote_user_status_index');
            $table->index(['expires_at'], 'v2_order_upgrade_quote_expires_at_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_order_upgrade_quote');
    }
};
