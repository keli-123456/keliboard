<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('v2_notice') || Schema::hasColumn('v2_notice', 'scope_type')) {
            return;
        }

        Schema::table('v2_notice', function (Blueprint $table): void {
            $table->string('scope_type', 16)->default('global')->after('id')->index();
        });

        DB::table('v2_notice')
            ->whereNotNull('site_id')
            ->update(['scope_type' => 'site']);
    }

    public function down(): void
    {
        if (!Schema::hasTable('v2_notice') || !Schema::hasColumn('v2_notice', 'scope_type')) {
            return;
        }

        Schema::table('v2_notice', function (Blueprint $table): void {
            $table->dropColumn('scope_type');
        });
    }
};
