<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('v2_machine_risk_credential_action')) return;
        Schema::create('v2_machine_risk_credential_action', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('site_id');
            $table->unsignedBigInteger('event_id')->unique();
            $table->unsignedInteger('policy_revision');
            $table->unsignedBigInteger('authorized_by')->nullable();
            $table->unsignedBigInteger('leak_review_id');
            $table->string('action', 24);
            $table->char('pre_epoch', 64);
            $table->char('post_epoch', 64);
            $table->unsignedBigInteger('user_sync_revision')->nullable();
            $table->unsignedInteger('rotated_at')->index();
            $table->unsignedBigInteger('repeat_event_id')->nullable()->index();
            $table->unsignedInteger('repeat_at')->nullable()->index();
            $table->string('repeat_verdict', 24)->nullable();
            $table->unique(['user_id', 'pre_epoch'], 'machine_risk_credential_user_epoch_unique');
            $table->index(['user_id', 'id'], 'machine_risk_credential_user_latest');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_machine_risk_credential_action');
    }
};
