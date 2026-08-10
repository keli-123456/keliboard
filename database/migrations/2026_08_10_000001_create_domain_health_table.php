<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('v2_domain_health')) {
            return;
        }

        Schema::create('v2_domain_health', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('domain', 255)->unique();
            $table->string('source_type', 32)->index();
            $table->unsignedInteger('source_id')->nullable();
            $table->unsignedInteger('owner_id')->nullable()->index();
            $table->string('source_name', 191)->nullable();
            $table->string('configured_status', 20)->nullable();
            $table->boolean('monitored')->default(true)->index();
            $table->string('status', 20)->default('unknown')->index();
            $table->string('reason', 64)->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->unsignedInteger('response_ms')->nullable();
            $table->json('dns_addresses')->nullable();
            $table->integer('certificate_expires_at')->nullable()->index();
            $table->string('certificate_issuer', 255)->nullable();
            $table->string('certificate_sha256', 64)->nullable();
            $table->text('last_error')->nullable();
            $table->unsignedSmallInteger('consecutive_failures')->default(0);
            $table->boolean('alert_active')->default(false)->index();
            $table->integer('last_checked_at')->nullable()->index();
            $table->integer('last_success_at')->nullable();
            $table->integer('last_failure_at')->nullable();
            $table->integer('alerted_at')->nullable();
            $table->integer('recovered_at')->nullable();
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
            $table->index(['source_type', 'source_id'], 'idx_domain_health_source');
            $table->index(['monitored', 'status'], 'idx_domain_health_monitor_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_domain_health');
    }
};
