<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('v2_marketing_template')) {
            return;
        }

        $this->dropUniqueIndexIfExists('v2_marketing_template', [
            'v2_marketing_template_code_unique',
            'code',
        ]);

        if (!$this->hasIndex('v2_marketing_template', 'idx_marketing_template_code')) {
            Schema::table('v2_marketing_template', function (Blueprint $table): void {
                $table->index('code', 'idx_marketing_template_code');
            });
        }

        if (
            Schema::hasColumn('v2_marketing_template', 'scope_type')
            && !$this->hasIndex('v2_marketing_template', 'idx_marketing_template_scope_lookup')
        ) {
            Schema::table('v2_marketing_template', function (Blueprint $table): void {
                $table->index(
                    ['code', 'channel', 'message_type', 'scope_type', 'site_id', 'agent_user_id', 'agent_domain_id'],
                    'idx_marketing_template_scope_lookup'
                );
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('v2_marketing_template')) {
            return;
        }

        foreach (['idx_marketing_template_scope_lookup', 'idx_marketing_template_code'] as $index) {
            $this->dropIndexIfExists('v2_marketing_template', $index);
        }

        if (!$this->hasIndex('v2_marketing_template', 'v2_marketing_template_code_unique')) {
            try {
                Schema::table('v2_marketing_template', function (Blueprint $table): void {
                    $table->unique('code', 'v2_marketing_template_code_unique');
                });
            } catch (\Throwable) {
                // A rollback can fail once scoped overrides share the same code.
            }
        }
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

    private function dropIndexIfExists(string $tableName, string $index): void
    {
        if (!$this->hasIndex($tableName, $index)) {
            return;
        }

        try {
            Schema::table($tableName, function (Blueprint $table) use ($index): void {
                $table->dropIndex($index);
            });
        } catch (\Throwable) {
            // Keep rollback tolerant across database drivers.
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
