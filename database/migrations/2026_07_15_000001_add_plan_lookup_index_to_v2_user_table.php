<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasIndex('v2_user', ['plan_id', 'expired_at'])) {
            Schema::table('v2_user', function (Blueprint $table) {
                $table->index(['plan_id', 'expired_at'], 'idx_v2_user_plan_expired');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex('v2_user', 'idx_v2_user_plan_expired')) {
            Schema::table('v2_user', function (Blueprint $table) {
                $table->dropIndex('idx_v2_user_plan_expired');
            });
        }
    }
};
