<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('v2_machine_dns_set_runtime', function (Blueprint $t) {
            $t->uuid('run_id')->primary(); $t->string('mode', 20);
            $t->unsignedInteger('generation')->default(0); $t->string('lease_hash', 64)->nullable();
            $t->unsignedInteger('lease_until')->nullable(); $t->unsignedInteger('next_check_at')->nullable();
            $t->unsignedInteger('stop_requested_at')->nullable(); $t->unsignedInteger('finished_at')->nullable();
            $t->unsignedInteger('created_at'); $t->unsignedInteger('updated_at');
        });
        Schema::create('v2_machine_dns_set_fixture', function (Blueprint $t) {
            $t->uuid('run_id')->primary(); $t->longText('payload');
            $t->unsignedInteger('created_at'); $t->unsignedInteger('updated_at');
        });
    }
    public function down(): void
    {
        foreach (['runtime', 'fixture'] as $name) if (Schema::hasTable('v2_machine_dns_set_' . $name)
            && DB::table('v2_machine_dns_set_' . $name)->exists()) throw new RuntimeException('DNS execution evidence must be retained before downgrade.');
        Schema::dropIfExists('v2_machine_dns_set_fixture'); Schema::dropIfExists('v2_machine_dns_set_runtime');
    }
};
