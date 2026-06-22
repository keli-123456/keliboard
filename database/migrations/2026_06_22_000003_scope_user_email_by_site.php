<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('v2_user')) {
            return;
        }

        if (!Schema::hasColumn('v2_user', 'site_id')) {
            Schema::table('v2_user', function (Blueprint $table): void {
                $table->unsignedInteger('site_id')->nullable()->index()->after('id');
            });
        }

        $defaultSiteId = $this->defaultSiteId();
        if ($defaultSiteId) {
            DB::table('v2_user')
                ->whereNull('site_id')
                ->update(['site_id' => $defaultSiteId]);
        }

        $this->dropUniqueIndexIfExists('v2_user', ['email', 'v2_user_email_unique']);

        if (!$this->hasIndex('v2_user', 'uniq_user_site_email')) {
            Schema::table('v2_user', function (Blueprint $table): void {
                $table->unique(['site_id', 'email'], 'uniq_user_site_email');
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('v2_user')) {
            return;
        }

        $this->dropUniqueIndexIfExists('v2_user', ['uniq_user_site_email']);

        if (!$this->hasIndex('v2_user', 'email') && !$this->hasIndex('v2_user', 'v2_user_email_unique')) {
            try {
                Schema::table('v2_user', function (Blueprint $table): void {
                    $table->unique('email', 'email');
                });
            } catch (\Throwable) {
                // A down migration can fail when duplicate emails already exist across sites.
            }
        }
    }

    private function defaultSiteId(): ?int
    {
        if (!Schema::hasTable('v2_site')) {
            return null;
        }

        $site = DB::table('v2_site')
            ->where('is_default', true)
            ->where('status', 'active')
            ->first();

        if ($site) {
            return (int) $site->id;
        }

        $now = time();
        $existing = DB::table('v2_site')->where('code', 'default')->first();
        if ($existing) {
            DB::table('v2_site')
                ->where('id', $existing->id)
                ->update([
                    'name' => $existing->name ?: 'Default Site',
                    'status' => 'active',
                    'is_default' => true,
                    'updated_at' => $now,
                ]);

            return (int) $existing->id;
        }

        return (int) DB::table('v2_site')->insertGetId([
            'code' => 'default',
            'name' => 'Default Site',
            'status' => 'active',
            'is_default' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * @param array<int, string> $indexes
     */
    private function dropUniqueIndexIfExists(string $tableName, array $indexes): void
    {
        foreach ($indexes as $index) {
            if (!$this->hasIndex($tableName, $index)) {
                continue;
            }

            try {
                Schema::table($tableName, function (Blueprint $table) use ($index): void {
                    $table->dropUnique($index);
                });
            } catch (\Throwable) {
                // Older installs may have driver-specific names; try the next known index.
            }
        }
    }

    private function hasIndex(string $tableName, string $indexName): bool
    {
        try {
            return Schema::hasIndex($tableName, $indexName);
        } catch (\Throwable) {
            return false;
        }
    }
};
