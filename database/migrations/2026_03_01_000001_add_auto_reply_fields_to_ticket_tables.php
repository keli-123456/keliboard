<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('v2_ticket')) {
            Schema::table('v2_ticket', function (Blueprint $table) {
                if (!Schema::hasColumn('v2_ticket', 'auto_reply_count')) {
                    $table->integer('auto_reply_count')->default(0)->comment('自动回复次数');
                }
                if (!Schema::hasColumn('v2_ticket', 'auto_reply_last_at')) {
                    $table->integer('auto_reply_last_at')->nullable()->comment('最后自动回复时间');
                }
                if (!Schema::hasColumn('v2_ticket', 'last_auto_reply_rule')) {
                    $table->string('last_auto_reply_rule', 120)->nullable()->comment('最后命中自动回复规则');
                }
            });
        }

        if (Schema::hasTable('v2_ticket_message')) {
            Schema::table('v2_ticket_message', function (Blueprint $table) {
                if (!Schema::hasColumn('v2_ticket_message', 'is_auto_reply')) {
                    $table->boolean('is_auto_reply')->default(false)->comment('是否自动回复');
                }
                if (!Schema::hasColumn('v2_ticket_message', 'auto_reply_rule')) {
                    $table->string('auto_reply_rule', 120)->nullable()->comment('自动回复命中规则');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('v2_ticket')) {
            Schema::table('v2_ticket', function (Blueprint $table) {
                if (Schema::hasColumn('v2_ticket', 'last_auto_reply_rule')) {
                    $table->dropColumn('last_auto_reply_rule');
                }
                if (Schema::hasColumn('v2_ticket', 'auto_reply_last_at')) {
                    $table->dropColumn('auto_reply_last_at');
                }
                if (Schema::hasColumn('v2_ticket', 'auto_reply_count')) {
                    $table->dropColumn('auto_reply_count');
                }
            });
        }

        if (Schema::hasTable('v2_ticket_message')) {
            Schema::table('v2_ticket_message', function (Blueprint $table) {
                if (Schema::hasColumn('v2_ticket_message', 'auto_reply_rule')) {
                    $table->dropColumn('auto_reply_rule');
                }
                if (Schema::hasColumn('v2_ticket_message', 'is_auto_reply')) {
                    $table->dropColumn('is_auto_reply');
                }
            });
        }
    }
};
