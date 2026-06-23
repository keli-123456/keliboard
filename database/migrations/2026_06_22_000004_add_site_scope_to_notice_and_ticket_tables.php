<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('v2_notice') && !Schema::hasColumn('v2_notice', 'site_id')) {
            Schema::table('v2_notice', function (Blueprint $table): void {
                $table->unsignedInteger('site_id')->nullable()->after('id')->index();
            });
        }

        if (Schema::hasTable('v2_ticket') && !Schema::hasColumn('v2_ticket', 'site_id')) {
            Schema::table('v2_ticket', function (Blueprint $table): void {
                $table->unsignedInteger('site_id')->nullable()->after('id')->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('v2_notice') && Schema::hasColumn('v2_notice', 'site_id')) {
            Schema::table('v2_notice', function (Blueprint $table): void {
                $table->dropColumn('site_id');
            });
        }

        if (Schema::hasTable('v2_ticket') && Schema::hasColumn('v2_ticket', 'site_id')) {
            Schema::table('v2_ticket', function (Blueprint $table): void {
                $table->dropColumn('site_id');
            });
        }
    }
};
