<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('v2_agent_prepaid_fund', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('agent_user_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('order_id')->unique();
            $table->unsignedBigInteger('amount');
            $table->unsignedBigInteger('fee_amount');
            $table->unsignedBigInteger('remaining_amount');
            $table->unsignedBigInteger('remaining_fee');
            $table->unsignedInteger('reversed_at')->nullable();
            $table->unsignedInteger('created_at');
            $table->unsignedInteger('updated_at');
            $table->index(['agent_user_id', 'user_id', 'id'], 'agent_prepaid_owner');
        });
        Schema::create('v2_agent_prepaid_allocation', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('fund_id');
            $table->unsignedBigInteger('amount');
            $table->unsignedBigInteger('fee_amount');
            $table->string('status', 16);
            $table->unsignedInteger('created_at');
            $table->unsignedInteger('updated_at');
            $table->unique(['order_id', 'fund_id'], 'agent_prepaid_allocation_unique');
            $table->index(['fund_id', 'status'], 'agent_prepaid_source');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Prepaid funds are financial records; reconcile before downgrading.');
    }
};
