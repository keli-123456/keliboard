<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('v2_machine_dns_transition', function (Blueprint $table) {
            $table->unsignedInteger('next_check_at')->nullable();
            $table->unsignedInteger('readback_attempts')->default(0);
            $table->unsignedInteger('last_checked_at')->nullable();
            $table->unsignedInteger('confirmed_at')->nullable();
        });
    }
    public function down(): void
    {
        if (!Schema::hasTable('v2_machine_dns_transition')) return;
        if (DB::table('v2_machine_dns_transition')->where('readback_attempts', '>', 0)->orWhereNotNull('next_check_at')->orWhereNotNull('confirmed_at')->exists())
            throw new \RuntimeException('DNS readback evidence must be retained; downgrade refused.');
        Schema::table('v2_machine_dns_transition', function (Blueprint $table) {
            $table->dropColumn(['next_check_at', 'readback_attempts', 'last_checked_at', 'confirmed_at']);
        });
    }
};
