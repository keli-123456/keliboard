<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('v2_ai_diagnostic_disposition')) {
            return;
        }

        Schema::create('v2_ai_diagnostic_disposition', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('report_id')->index();
            $table->string('scope_key', 64)->index();
            $table->string('finding_key', 96)->index();
            $table->unsignedBigInteger('subject_id')->default(0)->index();
            $table->string('status', 24)->default('open')->index();
            $table->text('note')->nullable();
            $table->integer('cooling_until')->nullable()->index();
            $table->unsignedInteger('admin_id')->nullable()->index();
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
            $table->unique(['report_id', 'finding_key', 'subject_id'], 'uniq_ai_disposition_report_finding');
            $table->index(
                ['scope_key', 'finding_key', 'subject_id', 'cooling_until'],
                'idx_ai_disposition_active_cooling'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_ai_diagnostic_disposition');
    }
};
