<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('v2_message_dispatch_task')) {
            Schema::table('v2_message_dispatch_task', function (Blueprint $table): void {
                if (!Schema::hasColumn('v2_message_dispatch_task', 'recovery_count')) {
                    $table->integer('recovery_count')->default(0)->after('max_attempts');
                }
                if (!Schema::hasColumn('v2_message_dispatch_task', 'last_recovered_at')) {
                    $table->integer('last_recovered_at')->nullable()->index()->after('sent_at');
                }
            });
        }

        if (Schema::hasTable('v2_message_dispatch_log')) {
            Schema::table('v2_message_dispatch_log', function (Blueprint $table): void {
                if (!Schema::hasColumn('v2_message_dispatch_log', 'manual_note')) {
                    $table->text('manual_note')->nullable()->after('context');
                }
                if (!Schema::hasColumn('v2_message_dispatch_log', 'noted_by_admin_id')) {
                    $table->integer('noted_by_admin_id')->nullable()->index()->after('manual_note');
                }
                if (!Schema::hasColumn('v2_message_dispatch_log', 'noted_at')) {
                    $table->integer('noted_at')->nullable()->index()->after('noted_by_admin_id');
                }
            });
        }

        if (Schema::hasTable('v2_spam_registration_candidate')) {
            Schema::table('v2_spam_registration_candidate', function (Blueprint $table): void {
                if (!Schema::hasColumn('v2_spam_registration_candidate', 'noted_by_admin_id')) {
                    $table->integer('noted_by_admin_id')->nullable()->index()->after('manual_note');
                }
                if (!Schema::hasColumn('v2_spam_registration_candidate', 'noted_at')) {
                    $table->integer('noted_at')->nullable()->index()->after('noted_by_admin_id');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('v2_spam_registration_candidate')) {
            Schema::table('v2_spam_registration_candidate', function (Blueprint $table): void {
                $dropColumns = [];
                foreach (['noted_by_admin_id', 'noted_at'] as $column) {
                    if (Schema::hasColumn('v2_spam_registration_candidate', $column)) {
                        $dropColumns[] = $column;
                    }
                }
                if (!empty($dropColumns)) {
                    $table->dropColumn($dropColumns);
                }
            });
        }

        if (Schema::hasTable('v2_message_dispatch_log')) {
            Schema::table('v2_message_dispatch_log', function (Blueprint $table): void {
                $dropColumns = [];
                foreach (['manual_note', 'noted_by_admin_id', 'noted_at'] as $column) {
                    if (Schema::hasColumn('v2_message_dispatch_log', $column)) {
                        $dropColumns[] = $column;
                    }
                }
                if (!empty($dropColumns)) {
                    $table->dropColumn($dropColumns);
                }
            });
        }

        if (Schema::hasTable('v2_message_dispatch_task')) {
            Schema::table('v2_message_dispatch_task', function (Blueprint $table): void {
                $dropColumns = [];
                foreach (['recovery_count', 'last_recovered_at'] as $column) {
                    if (Schema::hasColumn('v2_message_dispatch_task', $column)) {
                        $dropColumns[] = $column;
                    }
                }
                if (!empty($dropColumns)) {
                    $table->dropColumn($dropColumns);
                }
            });
        }
    }
};
