<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('v2_website_proxy_health', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('machine_id');
            $table->unsignedBigInteger('site_id')->default(0);
            $table->string('route_fingerprint', 64);
            $table->string('endpoint_url', 1024);
            $table->string('upstream_url', 1024);
            $table->string('site_code', 128);
            $table->string('status', 16);
            $table->string('reason', 64);
            $table->boolean('tls_verified')->default(false);
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->unsignedSmallInteger('context_http_status')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->unsignedInteger('consecutive_successes')->default(0);
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->unsignedInteger('last_checked_at')->index();
            $table->unsignedInteger('last_success_at')->nullable();
            $table->timestamps();
            $table->unique(['machine_id', 'site_id'], 'website_proxy_health_target_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_website_proxy_health');
    }
};
