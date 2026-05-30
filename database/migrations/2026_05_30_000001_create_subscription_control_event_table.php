<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('v2_subscription_control_event')) {
            return;
        }

        Schema::create('v2_subscription_control_event', function (Blueprint $table): void {
            $table->integer('id', true);
            $table->string('event_id', 64)->unique();
            $table->integer('user_id')->nullable()->index();
            $table->string('email', 191)->nullable()->index();
            $table->string('code', 64)->index();
            $table->text('reason');
            $table->string('action', 32)->index();
            $table->string('client_ip', 64)->nullable()->index();
            $table->string('proxy_ip', 64)->nullable();
            $table->string('client_ip_source', 64)->nullable();
            $table->boolean('trusted_proxy')->nullable();
            $table->string('cf_ray', 128)->nullable();
            $table->integer('ip_asn')->nullable()->index();
            $table->string('ip_prefix', 128)->nullable();
            $table->string('ip_country', 8)->nullable()->index();
            $table->string('ip_registry', 32)->nullable();
            $table->string('ip_org', 191)->nullable();
            $table->string('ip_type', 32)->nullable()->index();
            $table->json('ip_risk_tags')->nullable();
            $table->text('user_agent')->nullable();
            $table->string('ua_category', 64)->nullable()->index();
            $table->json('ua_categories')->nullable();
            $table->string('region', 128)->nullable();
            $table->json('regions')->nullable();
            $table->json('online_regions')->nullable();
            $table->integer('online_ip_count')->nullable();
            $table->integer('source_user_count')->nullable();
            $table->integer('source_user_threshold')->nullable();
            $table->integer('ip_count')->nullable();
            $table->integer('risk_score')->nullable();
            $table->integer('score_threshold')->nullable();
            $table->integer('hit_count')->nullable();
            $table->json('signals')->nullable();
            $table->boolean('active_plan_user')->nullable();
            $table->bigInteger('used_traffic')->nullable();
            $table->bigInteger('transfer_enable')->nullable();
            $table->integer('threshold')->nullable();
            $table->boolean('cooldown_hit')->default(false);
            $table->boolean('email_sent')->default(false);
            $table->boolean('telegram_sent')->default(false);
            $table->integer('created_at')->index();
            $table->integer('updated_at');

            $table->index(['user_id', 'created_at']);
            $table->index(['client_ip', 'created_at']);
            $table->index(['code', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_subscription_control_event');
    }
};
