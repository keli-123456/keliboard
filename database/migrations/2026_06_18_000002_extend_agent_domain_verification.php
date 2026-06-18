<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('v2_agent_domain')) {
            return;
        }

        if (!Schema::hasColumn('v2_agent_domain', 'verification_token')) {
            Schema::table('v2_agent_domain', function (Blueprint $table): void {
                $table->string('verification_token', 128)->nullable()->after('remark');
            });
        }

        if (!Schema::hasColumn('v2_agent_domain', 'verification_type')) {
            Schema::table('v2_agent_domain', function (Blueprint $table): void {
                $table->string('verification_type', 16)->nullable()->after('verification_token');
            });
        }

        if (!Schema::hasColumn('v2_agent_domain', 'verified_at')) {
            Schema::table('v2_agent_domain', function (Blueprint $table): void {
                $table->integer('verified_at')->nullable()->after('verification_type');
            });
        }

        if (!Schema::hasColumn('v2_agent_domain', 'last_checked_at')) {
            Schema::table('v2_agent_domain', function (Blueprint $table): void {
                $table->integer('last_checked_at')->nullable()->after('verified_at');
            });
        }

        if (!Schema::hasColumn('v2_agent_domain', 'verification_error')) {
            Schema::table('v2_agent_domain', function (Blueprint $table): void {
                $table->string('verification_error', 255)->nullable()->after('last_checked_at');
            });
        }

        if (!Schema::hasColumn('v2_agent_domain', 'created_by_agent_id')) {
            Schema::table('v2_agent_domain', function (Blueprint $table): void {
                $table->unsignedInteger('created_by_agent_id')->nullable()->after('created_by_admin_id')->index();
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('v2_agent_domain')) {
            return;
        }

        if (
            Schema::hasColumn('v2_agent_domain', 'created_by_agent_id')
            && Schema::hasIndex('v2_agent_domain', ['created_by_agent_id'])
        ) {
            Schema::table('v2_agent_domain', function (Blueprint $table): void {
                $table->dropIndex('v2_agent_domain_created_by_agent_id_index');
            });
        }

        $columns = array_values(array_filter([
            Schema::hasColumn('v2_agent_domain', 'created_by_agent_id') ? 'created_by_agent_id' : null,
            Schema::hasColumn('v2_agent_domain', 'verification_error') ? 'verification_error' : null,
            Schema::hasColumn('v2_agent_domain', 'last_checked_at') ? 'last_checked_at' : null,
            Schema::hasColumn('v2_agent_domain', 'verified_at') ? 'verified_at' : null,
            Schema::hasColumn('v2_agent_domain', 'verification_type') ? 'verification_type' : null,
            Schema::hasColumn('v2_agent_domain', 'verification_token') ? 'verification_token' : null,
        ]));

        if ($columns !== []) {
            Schema::table('v2_agent_domain', function (Blueprint $table) use ($columns): void {
                $table->dropColumn($columns);
            });
        }
    }
};
