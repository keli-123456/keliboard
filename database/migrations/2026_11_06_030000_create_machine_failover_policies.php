<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('v2_machine_failover_policy', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80);
            $table->unsignedInteger('revision')->default(1);
            $table->string('slot', 80)->unique();
            $table->unsignedBigInteger('machine_id')->index();
            $table->string('machine_authority', 64);
            $table->unsignedBigInteger('account_id')->index();
            $table->unsignedInteger('account_revision');
            $table->string('region', 60);
            $table->string('size', 60);
            $table->string('image', 80);
            $table->unsignedInteger('failure_threshold');
            $table->unsignedInteger('stale_after_seconds');
            $table->unsignedInteger('cooldown_seconds');
            $table->decimal('monthly_limit_usd', 8, 2);
            $table->unsignedInteger('daily_limit');
            $table->unsignedBigInteger('dns_credential_id')->nullable()->index();
            $table->unsignedInteger('dns_credential_revision')->nullable();
            $table->string('dns_hostname', 253)->default('');
            $table->string('dns_scope', 64)->nullable()->unique();
            $table->boolean('isolation_confirmed')->default(false);
            $table->unsignedBigInteger('updated_by');
            $table->unsignedInteger('created_at');
            $table->unsignedInteger('updated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_machine_failover_policy');
    }
};
