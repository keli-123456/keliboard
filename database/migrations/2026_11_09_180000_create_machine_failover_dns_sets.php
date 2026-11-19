<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('v2_machine_failover_dns_set', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('service_id')->index(); $t->unsignedInteger('revision')->default(1);
            $t->unsignedBigInteger('credential_id'); $t->unsignedInteger('credential_revision');
            $t->string('provider', 20); $t->string('zone', 253); $t->string('zone_name', 253);
            $t->string('hostname', 253); $t->string('scope', 64)->unique(); $t->unsignedInteger('ttl');
            $t->unsignedBigInteger('updated_by'); $t->unsignedInteger('created_at'); $t->unsignedInteger('updated_at');
        });
        Schema::create('v2_machine_failover_dns_slot', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('set_id')->index(); $t->unsignedBigInteger('member_id')->index();
            $t->string('type', 4); $t->string('line', 64); $t->string('marker', 80)->unique();
            $t->unsignedInteger('created_at'); $t->unsignedInteger('updated_at');
            $t->unique(['set_id', 'member_id', 'type', 'line'], 'machine_dns_member_slot_once');
        });
        Schema::create('v2_machine_failover_dns_run', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->unsignedBigInteger('set_id')->index(); $t->unsignedBigInteger('service_id')->index();
            $t->unsignedInteger('set_revision'); $t->unsignedInteger('service_revision'); $t->unsignedBigInteger('admin_id');
            $t->string('request_key', 64)->unique(); $t->string('intent_hash', 64); $t->string('context_hash', 64);
            $t->string('scenario', 40); $t->string('status', 24); $t->string('reason', 60)->nullable();
            $t->unsignedInteger('cursor')->default(0); $t->longText('payload');
            $t->unsignedInteger('created_at'); $t->unsignedInteger('updated_at');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('v2_machine_failover_dns_run') && DB::table('v2_machine_failover_dns_run')->exists())
            throw new RuntimeException('DNS record-set simulation audit must be retained before downgrade.');
        if (Schema::hasTable('v2_machine_failover_dns_set') && DB::table('v2_machine_failover_dns_set')->exists())
            throw new RuntimeException('DNS record-set drafts must be retained before downgrade.');
        foreach (['run', 'slot', 'set'] as $name) Schema::dropIfExists('v2_machine_failover_dns_' . $name);
    }
};
