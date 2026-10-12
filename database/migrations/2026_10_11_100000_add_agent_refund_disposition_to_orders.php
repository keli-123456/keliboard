<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('v2_order', 'agent_refund_disposition')) {
            Schema::table('v2_order', function (Blueprint $table): void {
                $table->json('agent_refund_disposition')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('v2_order', 'agent_refund_disposition')) {
            Schema::table('v2_order', function (Blueprint $table): void {
                $table->dropColumn('agent_refund_disposition');
            });
        }
    }
};
