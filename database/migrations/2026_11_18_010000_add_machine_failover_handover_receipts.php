<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private const TABLE = 'v2_machine_failover_handover_grant';

    public function up(): void
    {
        Schema::table(self::TABLE, function (Blueprint $table) {
            $table->text('stop_receipt')->nullable();
            $table->longText('closure_receipt')->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table(self::TABLE)->whereNotNull('stop_receipt')->orWhereNotNull('closure_receipt')->exists()) {
            throw new RuntimeException('Failover handover receipts must be retained.');
        }
        Schema::table(self::TABLE, fn (Blueprint $table) => $table->dropColumn(['stop_receipt', 'closure_receipt']));
    }
};
