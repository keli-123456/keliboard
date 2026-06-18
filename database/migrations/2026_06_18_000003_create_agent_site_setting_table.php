<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('v2_agent_site_setting')) {
            Schema::create('v2_agent_site_setting', function (Blueprint $table): void {
                $table->increments('id');
                $table->unsignedInteger('agent_user_id')->index();
                $table->unsignedInteger('agent_domain_id')->nullable()->index();
                $table->string('setting_scope', 16)->default('default');
                $table->string('setting_key', 64)->default('default');
                $table->string('site_name', 80)->nullable();
                $table->string('logo_url', 500)->nullable();
                $table->string('landing_theme', 32)->nullable();
                $table->string('accent_color', 16)->nullable();
                $table->string('support_name', 80)->nullable();
                $table->string('support_url', 500)->nullable();
                $table->string('announcement', 500)->nullable();
                $table->string('seo_title', 120)->nullable();
                $table->string('seo_description', 255)->nullable();
                $table->boolean('enabled')->default(true)->index();
                $table->integer('created_at')->nullable();
                $table->integer('updated_at')->nullable();
                $table->unique(['agent_user_id', 'setting_scope', 'setting_key'], 'uniq_agent_site_setting_scope');
            });
        }

        if (!Schema::hasTable('v2_ticket')) {
            return;
        }

        if (!Schema::hasColumn('v2_ticket', 'agent_user_id')) {
            Schema::table('v2_ticket', function (Blueprint $table): void {
                $table->unsignedInteger('agent_user_id')->nullable()->after('user_id')->index();
            });
        }

        if (!Schema::hasColumn('v2_ticket', 'agent_domain_id')) {
            Schema::table('v2_ticket', function (Blueprint $table): void {
                $table->unsignedInteger('agent_domain_id')->nullable()->after('agent_user_id')->index();
            });
        }
    }

    public function down(): void
    {
        // Rollback is intentionally conservative: this migration cannot prove ownership of pre-existing tables or columns.
    }
};
