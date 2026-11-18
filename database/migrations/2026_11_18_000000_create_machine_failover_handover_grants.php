<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private const TABLE = 'v2_machine_failover_handover_grant';

    public function up(): void
    {
        Schema::create(self::TABLE, function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('service_id')->index();
            $table->unsignedInteger('service_revision');
            $table->unsignedBigInteger('admin_id');
            $table->string('context_hash', 64);
            $table->text('release');
            $table->string('evidence_sha256', 64)->unique('machine_handover_evidence_once');
            $table->unsignedTinyInteger('writer_slot')->nullable()->unique('machine_handover_one_writer');
            $table->uuid('execution_id')->nullable()->unique('machine_handover_execution_once');
            $table->unsignedInteger('issued_at');
            $table->unsignedInteger('expires_at');
            $table->unsignedInteger('revoked_at')->nullable();
            $table->unsignedBigInteger('revoked_by')->nullable();
            $table->unsignedInteger('released_at')->nullable();
            $table->unsignedBigInteger('released_by')->nullable();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable(self::TABLE) && DB::table(self::TABLE)->exists()) {
            throw new RuntimeException('Failover handover authorizations must be retained.');
        }
        Schema::dropIfExists(self::TABLE);
    }
};
