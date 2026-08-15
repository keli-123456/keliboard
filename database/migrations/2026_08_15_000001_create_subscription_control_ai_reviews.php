<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('v2_subscription_control_ai_review')) {
            return;
        }

        Schema::create('v2_subscription_control_ai_review', function (Blueprint $table): void {
            $table->id();
            $table->string('status', 32)->default('pending')->index();
            $table->unsignedTinyInteger('window_days')->default(7);
            $table->unsignedInteger('event_count')->default(0);
            $table->unsignedTinyInteger('health_score')->nullable();
            $table->text('summary')->nullable();
            $table->json('current_config')->nullable();
            $table->json('metrics')->nullable();
            $table->json('findings')->nullable();
            $table->json('suggestions')->nullable();
            $table->json('replay')->nullable();
            $table->json('applied_changes')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->unsignedBigInteger('admin_id')->nullable()->index();
            $table->unsignedInteger('generated_at')->nullable();
            $table->unsignedInteger('applied_at')->nullable();
            $table->unsignedInteger('rolled_back_at')->nullable();
            $table->unsignedInteger('created_at');
            $table->unsignedInteger('updated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_subscription_control_ai_review');
    }
};
