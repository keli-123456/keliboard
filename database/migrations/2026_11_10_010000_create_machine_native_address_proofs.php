<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('v2_machine_native_address_proof', function (Blueprint $t) {
            $t->string('id', 32)->primary(); $t->uuid('attempt_id')->index(); $t->unsignedBigInteger('instance_id')->index();
            $t->string('family', 4); $t->string('ip', 45); $t->string('state', 20); $t->string('reason', 60)->nullable();
            $t->string('context_hash', 64); $t->string('request_sha256', 64); $t->string('receipt_sha256', 64)->nullable();
            $t->string('seal', 64)->nullable(); $t->longText('payload');
            $t->unsignedInteger('issued_at'); $t->unsignedInteger('deadline_at'); $t->unsignedInteger('valid_until')->nullable();
            $t->unique(['attempt_id', 'family'], 'machine_native_address_once');
        });
    }
    public function down(): void
    {
        if (Schema::hasTable('v2_machine_native_address_proof') && DB::table('v2_machine_native_address_proof')->exists())
            throw new RuntimeException('Native address proof audit must be retained before downgrade.');
        Schema::dropIfExists('v2_machine_native_address_proof');
    }
};
