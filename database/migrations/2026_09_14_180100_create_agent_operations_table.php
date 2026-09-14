<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('v2_agent_operation', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedInteger('agent_user_id');
            $table->string('request_key', 64);
            $table->string('fingerprint', 64);
            $table->text('result');
            $table->unsignedInteger('created_at');
            $table->unique(['agent_user_id', 'request_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_agent_operation');
    }
};
