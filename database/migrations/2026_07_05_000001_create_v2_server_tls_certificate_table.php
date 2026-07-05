<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('v2_server_tls_certificate')) {
            return;
        }

        Schema::create('v2_server_tls_certificate', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('server_id');
            $table->unsignedBigInteger('machine_id');
            $table->string('protocol', 32);
            $table->string('sni', 255)->default('');
            $table->string('status', 32)->default('valid');
            $table->string('sha256_hex', 64)->nullable();
            $table->string('sha256_base64', 128)->nullable();
            $table->timestamp('changed_at')->nullable();
            $table->timestamp('reported_at')->nullable();
            $table->timestamps();

            $table->unique(['server_id', 'machine_id', 'protocol', 'sni'], 'v2_tls_cert_unique');
            $table->index(['server_id', 'protocol', 'status'], 'v2_tls_cert_lookup');
            $table->index(['machine_id', 'reported_at'], 'v2_tls_cert_machine_reported');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_server_tls_certificate');
    }
};
