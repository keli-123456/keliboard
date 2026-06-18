<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
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
        } else {
            if (!Schema::hasColumn('v2_agent_site_setting', 'setting_scope')) {
                Schema::table('v2_agent_site_setting', function (Blueprint $table): void {
                    $table->string('setting_scope', 16)->default('default')->after('agent_domain_id');
                });
            }

            if (!Schema::hasColumn('v2_agent_site_setting', 'setting_key')) {
                Schema::table('v2_agent_site_setting', function (Blueprint $table): void {
                    $table->string('setting_key', 64)->default('default')->after('setting_scope');
                });
            }

            DB::table('v2_agent_site_setting')
                ->whereNull('agent_domain_id')
                ->update([
                    'setting_scope' => 'default',
                    'setting_key' => 'default',
                ]);

            DB::table('v2_agent_site_setting')
                ->whereNotNull('agent_domain_id')
                ->orderBy('id')
                ->select(['id', 'agent_domain_id'])
                ->get()
                ->each(function ($setting): void {
                    DB::table('v2_agent_site_setting')
                        ->where('id', $setting->id)
                        ->update([
                            'setting_scope' => 'domain',
                            'setting_key' => (string) $setting->agent_domain_id,
                        ]);
                });

            if (Schema::hasIndex('v2_agent_site_setting', ['agent_user_id', 'agent_domain_id'], 'unique')) {
                Schema::table('v2_agent_site_setting', function (Blueprint $table): void {
                    $table->dropIndex('uniq_agent_site_setting_domain');
                });
            }

            if (!Schema::hasIndex('v2_agent_site_setting', ['agent_user_id', 'setting_scope', 'setting_key'], 'unique')) {
                Schema::table('v2_agent_site_setting', function (Blueprint $table): void {
                    $table->unique(['agent_user_id', 'setting_scope', 'setting_key'], 'uniq_agent_site_setting_scope');
                });
            }
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
        if (Schema::hasTable('v2_ticket')) {
            if (
                Schema::hasColumn('v2_ticket', 'agent_domain_id')
                && Schema::hasIndex('v2_ticket', ['agent_domain_id'])
            ) {
                Schema::table('v2_ticket', function (Blueprint $table): void {
                    $table->dropIndex('v2_ticket_agent_domain_id_index');
                });
            }

            if (
                Schema::hasColumn('v2_ticket', 'agent_user_id')
                && Schema::hasIndex('v2_ticket', ['agent_user_id'])
            ) {
                Schema::table('v2_ticket', function (Blueprint $table): void {
                    $table->dropIndex('v2_ticket_agent_user_id_index');
                });
            }

            Schema::table('v2_ticket', function (Blueprint $table): void {
                $columns = array_values(array_filter([
                    Schema::hasColumn('v2_ticket', 'agent_domain_id') ? 'agent_domain_id' : null,
                    Schema::hasColumn('v2_ticket', 'agent_user_id') ? 'agent_user_id' : null,
                ]));

                if ($columns !== []) {
                    $table->dropColumn($columns);
                }
            });
        }

        Schema::dropIfExists('v2_agent_site_setting');
    }
};
