<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('v2_domain_metric_daily')) {
            Schema::create('v2_domain_metric_daily', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('record_date', 10);
                $table->string('host', 191);
                $table->integer('site_id')->nullable()->index();
                $table->integer('site_domain_id')->nullable()->index();
                $table->integer('agent_user_id')->nullable()->index();
                $table->integer('agent_domain_id')->nullable()->index();
                $table->unsignedBigInteger('page_views')->default(0);
                $table->unsignedBigInteger('unique_visitors')->default(0);
                $table->unsignedBigInteger('registrations')->default(0);
                $table->unsignedBigInteger('orders_created')->default(0);
                $table->unsignedBigInteger('orders_paid')->default(0);
                $table->unsignedBigInteger('revenue_amount')->default(0);
                $table->unsignedBigInteger('subscription_pulls')->default(0);
                $table->integer('created_at');
                $table->integer('updated_at');
                $table->unique(['record_date', 'host'], 'v2_domain_metric_daily_date_host_unique');
                $table->index(['host', 'record_date'], 'v2_domain_metric_daily_host_date_idx');
            });
        }

        if (!Schema::hasTable('v2_domain_visitor_daily')) {
            Schema::create('v2_domain_visitor_daily', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('record_date', 10);
                $table->string('host', 191);
                $table->char('visitor_hash', 64);
                $table->integer('created_at');
                $table->unique(['record_date', 'host', 'visitor_hash'], 'v2_domain_visitor_daily_unique');
                $table->index('record_date', 'v2_domain_visitor_daily_date_idx');
            });
        }

        if (Schema::hasTable('v2_order') && !Schema::hasColumn('v2_order', 'analytics_host')) {
            Schema::table('v2_order', function (Blueprint $table): void {
                $table->string('analytics_host', 191)->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('v2_order') && Schema::hasColumn('v2_order', 'analytics_host')) {
            Schema::table('v2_order', function (Blueprint $table): void {
                $table->dropColumn('analytics_host');
            });
        }
        Schema::dropIfExists('v2_domain_visitor_daily');
        Schema::dropIfExists('v2_domain_metric_daily');
    }
};
