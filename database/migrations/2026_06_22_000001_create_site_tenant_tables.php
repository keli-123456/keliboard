<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('v2_site')) {
            Schema::create('v2_site', function (Blueprint $table): void {
                $table->increments('id');
                $table->string('code', 64)->unique();
                $table->string('name', 120);
                $table->string('status', 20)->default('active')->index();
                $table->boolean('is_default')->default(false)->index();
                $table->integer('created_at')->nullable();
                $table->integer('updated_at')->nullable();
            });
        }

        if (!Schema::hasTable('v2_site_domain')) {
            Schema::create('v2_site_domain', function (Blueprint $table): void {
                $table->increments('id');
                $table->unsignedInteger('site_id')->index();
                $table->string('domain', 255)->unique();
                $table->string('status', 20)->default('active')->index();
                $table->boolean('is_primary')->default(false);
                $table->integer('created_at')->nullable();
                $table->integer('updated_at')->nullable();
                $table->index(['site_id', 'status']);
            });
        }

        if (Schema::hasTable('v2_user') && !Schema::hasColumn('v2_user', 'site_id')) {
            Schema::table('v2_user', function (Blueprint $table): void {
                $table->unsignedInteger('site_id')->nullable()->index()->after('id');
            });
        }

        if (Schema::hasTable('v2_order') && !Schema::hasColumn('v2_order', 'site_id')) {
            Schema::table('v2_order', function (Blueprint $table): void {
                $table->unsignedInteger('site_id')->nullable()->index()->after('id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('v2_order') && Schema::hasColumn('v2_order', 'site_id')) {
            Schema::table('v2_order', function (Blueprint $table): void {
                $table->dropColumn('site_id');
            });
        }

        if (Schema::hasTable('v2_user') && Schema::hasColumn('v2_user', 'site_id')) {
            Schema::table('v2_user', function (Blueprint $table): void {
                $table->dropColumn('site_id');
            });
        }

        Schema::dropIfExists('v2_site_domain');
        Schema::dropIfExists('v2_site');
    }
};
