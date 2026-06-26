<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('v2_site_setting')) {
            return;
        }

        if (!Schema::hasColumn('v2_site_setting', 'telegram_discuss_link')) {
            Schema::table('v2_site_setting', function (Blueprint $table): void {
                $table->string('telegram_discuss_link', 500)->nullable()->after('support_url');
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('v2_site_setting')) {
            return;
        }

        if (Schema::hasColumn('v2_site_setting', 'telegram_discuss_link')) {
            Schema::table('v2_site_setting', function (Blueprint $table): void {
                $table->dropColumn('telegram_discuss_link');
            });
        }
    }
};
