<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('v2_agent_domain')) {
            Schema::create('v2_agent_domain', function (Blueprint $table): void {
                $table->increments('id');
                $table->unsignedInteger('agent_user_id')->index();
                $table->string('domain', 255)->unique();
                $table->string('status', 20)->default('active')->index();
                $table->boolean('is_primary')->default(false);
                $table->string('remark')->nullable();
                $table->unsignedInteger('created_by_admin_id')->nullable();
                $table->integer('created_at')->nullable();
                $table->integer('updated_at')->nullable();
                $table->index(['agent_user_id', 'status']);
            });
        }

        if (!Schema::hasTable('v2_agent_plan_price')) {
            Schema::create('v2_agent_plan_price', function (Blueprint $table): void {
                $table->increments('id');
                $table->unsignedInteger('agent_user_id')->index();
                $table->unsignedInteger('plan_id')->index();
                $table->string('period', 32);
                $table->integer('sale_price')->default(0);
                $table->boolean('enabled')->default(true);
                $table->integer('created_at')->nullable();
                $table->integer('updated_at')->nullable();
                $table->unique(['agent_user_id', 'plan_id', 'period'], 'uniq_agent_plan_period');
            });
        }

        if (!Schema::hasTable('v2_agent_balance_hold')) {
            Schema::create('v2_agent_balance_hold', function (Blueprint $table): void {
                $table->increments('id');
                $table->unsignedInteger('agent_user_id')->index();
                $table->unsignedInteger('order_id')->unique();
                $table->string('trade_no', 64)->unique();
                $table->integer('amount')->default(0);
                $table->string('status', 20)->default('pending')->index();
                $table->integer('expires_at')->nullable();
                $table->integer('captured_at')->nullable();
                $table->integer('released_at')->nullable();
                $table->json('metadata')->nullable();
                $table->integer('created_at')->nullable();
                $table->integer('updated_at')->nullable();
                $table->index(['agent_user_id', 'status']);
            });
        }

        if (!Schema::hasTable('v2_agent_order_context')) {
            Schema::create('v2_agent_order_context', function (Blueprint $table): void {
                $table->increments('id');
                $table->unsignedInteger('order_id')->unique();
                $table->string('trade_no', 64)->unique();
                $table->unsignedInteger('agent_user_id')->index();
                $table->unsignedInteger('agent_domain_id')->nullable()->index();
                $table->unsignedInteger('payment_id')->nullable();
                $table->integer('sale_amount')->default(0);
                $table->integer('cost_amount')->default(0);
                $table->unsignedInteger('hold_id')->nullable();
                $table->string('status', 20)->default('pending')->index();
                $table->json('pricing_snapshot')->nullable();
                $table->json('domain_snapshot')->nullable();
                $table->json('payment_snapshot')->nullable();
                $table->integer('created_at')->nullable();
                $table->integer('updated_at')->nullable();
                $table->index(['agent_user_id', 'status']);
            });
        }

        if (Schema::hasTable('v2_payment')) {
            Schema::table('v2_payment', function (Blueprint $table): void {
                if (!Schema::hasColumn('v2_payment', 'owner_type')) {
                    $table->string('owner_type', 20)->default('platform')->after('id');
                }
                if (!Schema::hasColumn('v2_payment', 'owner_id')) {
                    $table->unsignedInteger('owner_id')->nullable()->after('owner_type');
                }
                if (!Schema::hasColumn('v2_payment', 'owner_domain_id')) {
                    $table->unsignedInteger('owner_domain_id')->nullable()->after('owner_id');
                }
            });

            Schema::table('v2_payment', function (Blueprint $table): void {
                $table->index(['owner_type', 'owner_id'], 'idx_payment_owner');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('v2_payment')) {
            Schema::table('v2_payment', function (Blueprint $table): void {
                if (Schema::hasColumn('v2_payment', 'owner_type') && Schema::hasColumn('v2_payment', 'owner_id')) {
                    $table->dropIndex('idx_payment_owner');
                }
            });

            Schema::table('v2_payment', function (Blueprint $table): void {
                $columns = array_values(array_filter([
                    Schema::hasColumn('v2_payment', 'owner_domain_id') ? 'owner_domain_id' : null,
                    Schema::hasColumn('v2_payment', 'owner_id') ? 'owner_id' : null,
                    Schema::hasColumn('v2_payment', 'owner_type') ? 'owner_type' : null,
                ]));

                if ($columns !== []) {
                    $table->dropColumn($columns);
                }
            });
        }

        Schema::dropIfExists('v2_agent_order_context');
        Schema::dropIfExists('v2_agent_balance_hold');
        Schema::dropIfExists('v2_agent_plan_price');
        Schema::dropIfExists('v2_agent_domain');
    }
};
