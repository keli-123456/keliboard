<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('v2_ticket_ai_suggestion') && !Schema::hasColumn('v2_ticket_ai_suggestion', 'image_analysis')) {
            Schema::table('v2_ticket_ai_suggestion', fn (Blueprint $table) => $table->json('image_analysis')->nullable());
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('v2_ticket_ai_suggestion') && Schema::hasColumn('v2_ticket_ai_suggestion', 'image_analysis')) {
            Schema::table('v2_ticket_ai_suggestion', fn (Blueprint $table) => $table->dropColumn('image_analysis'));
        }
    }
};
