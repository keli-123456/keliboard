<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('v2_site_navigation')) {
            Schema::create('v2_site_navigation', function (Blueprint $table): void {
                $table->increments('id');
                $table->string('scope_key', 64)->unique();
                $table->unsignedInteger('site_id')->nullable()->index();
                $table->boolean('enabled')->default(false)->index();
                $table->string('title', 120)->nullable();
                $table->string('description', 500)->nullable();
                $table->string('announcement', 1000)->nullable();
                $table->integer('created_at')->nullable();
                $table->integer('updated_at')->nullable();
            });
        }

        if (!Schema::hasTable('v2_site_navigation_domain')) {
            Schema::create('v2_site_navigation_domain', function (Blueprint $table): void {
                $table->increments('id');
                $table->unsignedInteger('navigation_id')->index();
                $table->string('domain', 255)->unique();
                $table->string('status', 20)->default('active')->index();
                $table->boolean('is_primary')->default(false);
                $table->integer('sort')->default(0);
                $table->integer('created_at')->nullable();
                $table->integer('updated_at')->nullable();
                $table->index(['navigation_id', 'status'], 'idx_site_nav_domain_status');
            });
        }

        if (!Schema::hasTable('v2_site_navigation_link')) {
            Schema::create('v2_site_navigation_link', function (Blueprint $table): void {
                $table->increments('id');
                $table->unsignedInteger('navigation_id')->index();
                $table->string('label', 120);
                $table->string('url', 1000);
                $table->boolean('enabled')->default(true)->index();
                $table->integer('sort')->default(0);
                $table->integer('created_at')->nullable();
                $table->integer('updated_at')->nullable();
                $table->index(['navigation_id', 'enabled'], 'idx_site_nav_link_enabled');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_site_navigation_link');
        Schema::dropIfExists('v2_site_navigation_domain');
        Schema::dropIfExists('v2_site_navigation');
    }
};
