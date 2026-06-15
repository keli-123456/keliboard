<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('v2_agent_profile')) {
            Schema::create('v2_agent_profile', function (Blueprint $table): void {
                $table->increments('id');
                $table->unsignedInteger('user_id')->unique();
                $table->string('status', 32)->default('pending')->index();
                $table->string('level', 64)->default('default');
                $table->string('remark')->nullable();
                $table->integer('enabled_at')->nullable();
                $table->integer('disabled_at')->nullable();
                $table->integer('created_at')->nullable();
                $table->integer('updated_at')->nullable();
            });
        }

        if (!Schema::hasTable('v2_agent_user')) {
            Schema::create('v2_agent_user', function (Blueprint $table): void {
                $table->increments('id');
                $table->unsignedInteger('agent_user_id')->index();
                $table->unsignedInteger('sub_user_id')->unique();
                $table->string('remark')->nullable();
                $table->integer('created_at')->nullable();
                $table->integer('updated_at')->nullable();
            });
        }

        if (!Schema::hasTable('v2_agent_ledger')) {
            Schema::create('v2_agent_ledger', function (Blueprint $table): void {
                $table->increments('id');
                $table->unsignedInteger('agent_user_id')->index();
                $table->unsignedInteger('target_user_id')->nullable()->index();
                $table->string('type', 64)->index();
                $table->integer('amount')->default(0);
                $table->integer('balance_before')->default(0);
                $table->integer('balance_after')->default(0);
                $table->unsignedInteger('plan_id')->nullable();
                $table->string('period', 64)->nullable();
                $table->json('metadata')->nullable();
                $table->integer('created_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_agent_ledger');
        Schema::dropIfExists('v2_agent_user');
        Schema::dropIfExists('v2_agent_profile');
    }
};
