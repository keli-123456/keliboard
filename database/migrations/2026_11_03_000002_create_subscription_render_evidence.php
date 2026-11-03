<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('v2_subscription_render_evidence', function (Blueprint $table): void {
            $table->id();
            $table->char('receipt_key', 64)->unique();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('site_id');
            $table->char('credential_hash', 64);
            $table->unsignedInteger('policy_revision');
            $table->unsignedInteger('generated_at')->index();
            $table->unsignedInteger('persisted_at');
            $table->string('format', 32);
            $table->text('nodes');
            $table->boolean('capped');
            $table->index(['user_id', 'site_id', 'generated_at'], 'subscription_render_user_time');
        });
    }

    public function down(): void { Schema::dropIfExists('v2_subscription_render_evidence'); }
};
