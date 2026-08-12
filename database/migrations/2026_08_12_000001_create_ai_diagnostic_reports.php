<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('v2_ai_diagnostic_report')) {
            return;
        }

        Schema::create('v2_ai_diagnostic_report', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('scope_key', 64)->index();
            $table->string('scope_type', 24)->index();
            $table->unsignedInteger('site_id')->nullable()->index();
            $table->string('status', 20)->default('healthy')->index();
            $table->unsignedTinyInteger('score')->default(100);
            $table->json('summary')->nullable();
            $table->json('metrics')->nullable();
            $table->json('findings')->nullable();
            $table->text('ai_summary')->nullable();
            $table->string('ai_status', 20)->default('disabled');
            $table->string('generated_by', 20)->default('manual');
            $table->unsignedInteger('admin_id')->nullable()->index();
            $table->integer('generated_at')->index();
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
            $table->index(['scope_key', 'generated_at'], 'idx_ai_diagnostic_scope_generated');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_ai_diagnostic_report');
    }
};
