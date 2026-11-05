<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('v2_machine_dns_credential', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80);
            $table->string('provider', 20);
            $table->text('credentials');
            $table->unsignedInteger('revision')->default(1);
            $table->unsignedInteger('created_at');
            $table->unsignedInteger('updated_at');
        });
        Schema::create('v2_machine_dns_binding', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('credential_id')->index();
            $table->unsignedBigInteger('machine_id')->index();
            $table->string('installation_id', 32);
            $table->string('machine_authority', 64);
            $table->string('record_scope', 64)->unique();
            $table->string('zone', 253);
            $table->string('record_id', 64);
            $table->string('hostname', 253);
            $table->string('type', 4);
            $table->boolean('enabled')->default(false);
            $table->unsignedInteger('revision')->default(1);
            $table->string('last_ip', 45);
            $table->string('status', 40)->default('disabled');
            $table->unsignedInteger('last_verified_at')->nullable();
            $table->unsignedInteger('next_attempt_at')->default(0)->index();
            $table->unsignedInteger('created_at');
            $table->unsignedInteger('updated_at');
        });
        Schema::create('v2_machine_dns_log', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('binding_id')->nullable()->index();
            $table->unsignedBigInteger('credential_id')->nullable();
            $table->unsignedBigInteger('admin_id')->nullable();
            $table->string('action', 40);
            $table->string('hostname', 253)->nullable();
            $table->string('from_ip', 45)->nullable();
            $table->string('to_ip', 45)->nullable();
            $table->unsignedInteger('created_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_machine_dns_log');
        Schema::dropIfExists('v2_machine_dns_binding');
        Schema::dropIfExists('v2_machine_dns_credential');
    }
};
