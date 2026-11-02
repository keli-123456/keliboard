<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('v2_subscription_ai_policy', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->json('config');
            $table->unsignedBigInteger('revision')->default(1);
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->unsignedInteger('updated_at');
        });
        DB::table('v2_subscription_ai_policy')->insert(['id' => 1, 'config' => '{"ai_auto_enabled":false}', 'updated_at' => time()]);
        Schema::create('v2_subscription_ai_action', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('site_id')->default(0);
            $table->char('credential_hash', 64);
            $table->char('policy_hash', 64);
            $table->char('intent_key', 64)->unique();
            $table->string('status', 24)->default('pending');
            $table->string('action', 32)->nullable();
            $table->json('evidence');
            $table->json('authorization');
            $table->json('decision')->nullable();
            $table->string('outcome', 64)->nullable();
            $table->string('notification_status', 24)->nullable();
            $table->unsignedInteger('notification_started_at')->nullable();
            $table->unsignedInteger('expires_at')->nullable();
            $table->unsignedInteger('ended_at')->nullable();
            $table->unsignedInteger('checked_at')->nullable();
            $table->unsignedInteger('followup_events')->default(0);
            $table->unsignedBigInteger('revoked_by')->nullable();
            $table->unsignedInteger('created_at');
            $table->unsignedInteger('updated_at');
            $table->index(['user_id', 'site_id', 'status', 'expires_at'], 'sub_ai_active_user');
            $table->index(['status', 'created_at'], 'sub_ai_queue_age');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_subscription_ai_action');
        Schema::dropIfExists('v2_subscription_ai_policy');
    }
};
