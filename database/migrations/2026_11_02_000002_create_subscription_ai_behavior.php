<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('v2_subscription_ai_behavior', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('site_id');
            $table->char('credential_hash', 64);
            $table->unsignedInteger('bucket_at');
            $table->json('sample');
            $table->unsignedInteger('last_seen_at');
            $table->unsignedInteger('snapshot_at');
            $table->unique(['user_id', 'site_id', 'credential_hash', 'bucket_at'], 'sub_ai_behavior_bucket');
            $table->index('last_seen_at');
            $table->index('bucket_at');
        });
        Schema::create('v2_subscription_ai_policy_version', function (Blueprint $table): void {
            $table->unsignedBigInteger('revision')->primary();
            $table->json('config');
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->unsignedInteger('created_at');
        });
        $policy = DB::table('v2_subscription_ai_policy')->where('id', 1)->first();
        if ($policy) DB::table('v2_subscription_ai_policy_version')->insert([
            'revision' => $policy->revision, 'config' => $policy->config,
            'updated_by' => $policy->updated_by, 'created_at' => $policy->updated_at,
        ]);
        Schema::table('v2_subscription_ai_action', function (Blueprint $table): void {
            $table->unsignedBigInteger('policy_revision')->nullable();
            $table->string('review_verdict', 24)->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->unsignedInteger('reviewed_at')->nullable();
            $table->index(['policy_revision', 'review_verdict'], 'sub_ai_policy_review');
        });
    }

    public function down(): void
    {
        Schema::table('v2_subscription_ai_action', function (Blueprint $table): void {
            $table->dropIndex('sub_ai_policy_review');
            $table->dropColumn(['policy_revision', 'review_verdict', 'reviewed_by', 'reviewed_at']);
        });
        Schema::dropIfExists('v2_subscription_ai_policy_version');
        Schema::dropIfExists('v2_subscription_ai_behavior');
    }
};
