<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private const COLUMNS = [
        'quality_rating',
        'feedback_reason',
        'feedback_note',
        'feedback_admin_id',
        'feedback_at',
        'draft_chars',
        'final_chars',
        'similarity_score',
        'edit_ratio',
        'knowledge_hit_count',
        'knowledge_gap',
    ];

    public function up(): void
    {
        if (!Schema::hasTable('v2_ticket_ai_suggestion')) {
            return;
        }
        foreach (self::COLUMNS as $column) {
            if (Schema::hasColumn('v2_ticket_ai_suggestion', $column)) {
                throw new RuntimeException("v2_ticket_ai_suggestion.{$column} already exists; refusing a partial migration.");
            }
        }

        Schema::table('v2_ticket_ai_suggestion', function (Blueprint $table): void {
            $table->string('quality_rating', 24)->nullable()->index();
            $table->string('feedback_reason', 40)->nullable()->index();
            $table->string('feedback_note', 1000)->nullable();
            $table->unsignedInteger('feedback_admin_id')->nullable()->index();
            $table->integer('feedback_at')->nullable()->index();
            $table->unsignedInteger('draft_chars')->default(0);
            $table->unsignedInteger('final_chars')->default(0);
            $table->decimal('similarity_score', 5, 4)->nullable();
            $table->decimal('edit_ratio', 5, 4)->nullable()->index();
            $table->unsignedSmallInteger('knowledge_hit_count')->default(0)->index();
            $table->boolean('knowledge_gap')->default(false)->index();
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('v2_ticket_ai_suggestion')) {
            return;
        }

        $columns = array_values(array_filter(
            self::COLUMNS,
            static fn (string $column): bool => Schema::hasColumn('v2_ticket_ai_suggestion', $column)
        ));
        foreach (['quality_rating', 'feedback_reason', 'feedback_admin_id', 'feedback_at', 'edit_ratio', 'knowledge_hit_count', 'knowledge_gap'] as $column) {
            if (in_array($column, $columns, true)) {
                Schema::table('v2_ticket_ai_suggestion', fn (Blueprint $table) => $table->dropIndex([$column]));
            }
        }
        if ($columns !== []) {
            Schema::table('v2_ticket_ai_suggestion', fn (Blueprint $table) => $table->dropColumn($columns));
        }
    }
};
