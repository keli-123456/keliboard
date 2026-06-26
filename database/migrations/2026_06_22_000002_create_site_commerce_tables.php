<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('v2_site_setting')) {
            Schema::create('v2_site_setting', function (Blueprint $table): void {
                $table->increments('id');
                $table->unsignedInteger('site_id')->unique();
                $table->string('site_name', 120)->nullable();
                $table->string('logo_url', 500)->nullable();
                $table->string('landing_theme', 64)->nullable();
                $table->string('accent_color', 16)->nullable();
                $table->string('support_name', 120)->nullable();
                $table->string('support_url', 500)->nullable();
                $table->string('customer_service_type', 32)->nullable();
                $table->string('customer_service_id', 255)->nullable();
                $table->string('telegram_discuss_link', 500)->nullable();
                $table->string('announcement', 1000)->nullable();
                $table->string('seo_title', 160)->nullable();
                $table->string('seo_description', 255)->nullable();
                $table->boolean('enabled')->default(true)->index();
                $table->integer('created_at')->nullable();
                $table->integer('updated_at')->nullable();
            });
        }

        if (!Schema::hasTable('v2_site_plan_price')) {
            Schema::create('v2_site_plan_price', function (Blueprint $table): void {
                $table->increments('id');
                $table->unsignedInteger('site_id')->index();
                $table->unsignedInteger('plan_id')->index();
                $table->string('period', 32);
                $table->integer('sale_price')->default(0);
                $table->boolean('enabled')->default(true)->index();
                $table->integer('created_at')->nullable();
                $table->integer('updated_at')->nullable();
                $table->unique(['site_id', 'plan_id', 'period'], 'uniq_site_plan_period');
            });
        }

        if (!Schema::hasTable('v2_site_payment')) {
            Schema::create('v2_site_payment', function (Blueprint $table): void {
                $table->increments('id');
                $table->unsignedInteger('site_id')->index();
                $table->unsignedInteger('payment_id')->index();
                $table->boolean('enabled')->default(true)->index();
                $table->integer('sort')->nullable();
                $table->integer('created_at')->nullable();
                $table->integer('updated_at')->nullable();
                $table->unique(['site_id', 'payment_id'], 'uniq_site_payment');
            });
        }

        if (!Schema::hasTable('v2_site_order_context')) {
            Schema::create('v2_site_order_context', function (Blueprint $table): void {
                $table->increments('id');
                $table->unsignedInteger('order_id')->unique();
                $table->string('trade_no', 64)->unique();
                $table->unsignedInteger('site_id')->index();
                $table->unsignedInteger('site_domain_id')->nullable()->index();
                $table->integer('sale_amount')->default(0);
                $table->integer('platform_plan_price')->default(0);
                $table->json('pricing_snapshot')->nullable();
                $table->json('domain_snapshot')->nullable();
                $table->integer('created_at')->nullable();
                $table->integer('updated_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_site_order_context');
        Schema::dropIfExists('v2_site_payment');
        Schema::dropIfExists('v2_site_plan_price');
        Schema::dropIfExists('v2_site_setting');
    }
};
