<?php

use App\Models\GiftCardTemplate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addScopeColumns('v2_gift_card_template');
        $this->addScopeColumns('v2_gift_card_code');
        $this->addScopeColumns('v2_gift_card_usage');
    }

    public function down(): void
    {
        $this->dropScopeColumns('v2_gift_card_usage');
        $this->dropScopeColumns('v2_gift_card_code');
        $this->dropScopeColumns('v2_gift_card_template');
    }

    private function addScopeColumns(string $table): void
    {
        Schema::table($table, function (Blueprint $blueprint) use ($table): void {
            $scopeAfter = $table === 'v2_gift_card_usage' ? 'user_id' : 'status';
            if (!Schema::hasColumn($table, 'scope_type')) {
                $blueprint->string('scope_type', 16)
                    ->default(GiftCardTemplate::SCOPE_GLOBAL)
                    ->after($scopeAfter)
                    ->index($table . '_scope_type_idx');
            }
            if (!Schema::hasColumn($table, 'site_id')) {
                $blueprint->unsignedInteger('site_id')
                    ->nullable()
                    ->after('scope_type')
                    ->index($table . '_site_id_idx');
            }
            if (!Schema::hasColumn($table, 'agent_user_id')) {
                $blueprint->unsignedInteger('agent_user_id')
                    ->nullable()
                    ->after('site_id')
                    ->index($table . '_agent_user_id_idx');
            }
            if (!Schema::hasColumn($table, 'agent_domain_id')) {
                $blueprint->unsignedInteger('agent_domain_id')
                    ->nullable()
                    ->after('agent_user_id')
                    ->index($table . '_agent_domain_id_idx');
            }
        });
    }

    private function dropScopeColumns(string $table): void
    {
        Schema::table($table, function (Blueprint $blueprint) use ($table): void {
            foreach ([
                $table . '_scope_type_idx',
                $table . '_site_id_idx',
                $table . '_agent_user_id_idx',
                $table . '_agent_domain_id_idx',
            ] as $index) {
                try {
                    $blueprint->dropIndex($index);
                } catch (Throwable) {
                    // Older databases may not have the index if a partial migration was repaired manually.
                }
            }

            foreach (['scope_type', 'site_id', 'agent_user_id', 'agent_domain_id'] as $column) {
                if (Schema::hasColumn($table, $column)) {
                    $blueprint->dropColumn($column);
                }
            }
        });
    }
};
