<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('v2_subscription_control_case_review')) {
            return;
        }

        Schema::create('v2_subscription_control_case_review', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id')->index();
            $table->string('status', 32)->index();
            $table->text('note')->nullable();
            $table->json('evidence_snapshot')->nullable();
            $table->unsignedTinyInteger('suspicion_score')->nullable();
            $table->char('evidence_fingerprint', 64)->nullable()->index();
            $table->unsignedInteger('baseline_last_trigger_at')->nullable();
            $table->unsignedInteger('reviewed_at')->index();
            $table->unsignedBigInteger('admin_id')->nullable()->index();
            $table->unsignedInteger('created_at');
            $table->unsignedInteger('updated_at');
            $table->index(['user_id', 'reviewed_at'], 'idx_subscription_case_user_reviewed');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_subscription_control_case_review');
    }
};