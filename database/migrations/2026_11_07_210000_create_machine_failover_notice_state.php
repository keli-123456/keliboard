<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('v2_machine_failover_notice_state', function (Blueprint $t) {
            $t->unsignedBigInteger('member_id')->primary(); $t->text('signals');
            $t->unsignedInteger('progress_at')->default(0); $t->unsignedInteger('risk_at')->default(0);
            $t->unsignedInteger('next_scan_at')->index(); $t->unsignedInteger('created_at'); $t->unsignedInteger('updated_at');
        });
        DB::table('v2_machine_operation_notice_policy')->insert(['scope' => 'failover', 'enabled' => false, 'revision' => 1, 'updated_at' => time()]);
    }
    public function down(): void
    {
        if (DB::table('v2_machine_failover_notice_state')->exists()
            || DB::table('v2_machine_operation_notice')->where('scope', 'failover')->exists()) throw new RuntimeException('Native notification evidence must be retained.');
        DB::table('v2_machine_operation_notice_policy')->where('scope', 'failover')->delete();
        Schema::dropIfExists('v2_machine_failover_notice_state');
    }
};
