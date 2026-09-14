<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('v2_agent_collection', function (Blueprint $table) {
            $table->unsignedBigInteger('agent_user_id')->primary();
            $table->string('mode', 16)->default('self');
            $table->boolean('platform_enabled')->default(false);
            $table->text('payment_ids')->nullable();
            $table->unsignedInteger('fee_bps')->default(0);
            $table->unsignedInteger('fee_fixed')->default(0);
            $table->unsignedInteger('settlement_days')->default(7);
            $table->unsignedInteger('minimum_withdrawal')->default(1000);
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->unsignedInteger('updated_at');
        });
        Schema::create('v2_agent_profit_wallet', function (Blueprint $table) {
            $table->unsignedBigInteger('agent_user_id')->primary();
            foreach (['pending', 'available', 'frozen', 'debt'] as $column) {
                $table->unsignedBigInteger($column)->default(0);
            }
            $table->unsignedInteger('updated_at');
        });
        Schema::create('v2_agent_profit', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('agent_user_id')->index();
            $table->unsignedBigInteger('order_id')->unique();
            $table->string('trade_no', 64);
            foreach (['sale_amount', 'cost_amount', 'fee_amount', 'amount'] as $column) {
                $table->unsignedBigInteger($column);
            }
            $table->string('status', 16);
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
            $table->unsignedInteger('updated_at');
            $table->index(['status', 'available_at']);
        });
        Schema::create('v2_agent_profit_withdrawal', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('agent_user_id')->index();
            $table->string('request_key', 64);
            $table->string('fingerprint', 64);
            $table->unsignedBigInteger('amount');
            $table->string('method', 32);
            $table->text('account');
            $table->string('status', 16)->default('pending')->index();
            $table->string('reference', 255)->nullable()->unique();
            $table->string('note', 500)->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->unsignedInteger('created_at');
            $table->unsignedInteger('updated_at');
            $table->unique(['agent_user_id', 'request_key']);
        });
        Schema::create('v2_agent_profit_ledger', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('agent_user_id')->index();
            $table->string('event_key', 100)->unique();
            $table->text('before_state');
            $table->text('after_state');
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->unsignedInteger('created_at');
        });
    }

    public function down(): void
    {
        // Accounting records must be exported and reconciled before a destructive rollback.
        throw new RuntimeException('Agent profit accounting migration cannot be rolled back automatically.');
    }
};
