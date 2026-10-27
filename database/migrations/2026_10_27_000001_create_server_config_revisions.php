<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('v2_server_config_revision', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('server_id')->index();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('action', 16);
            $table->unsignedBigInteger('source_revision_id')->nullable();
            $table->longText('snapshot');
            $table->string('config_hash', 64);
            $table->string('event_id', 36)->nullable();
            $table->json('changes');
            $table->string('impact', 24);
            $table->timestamps();
            $table->index(['server_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_server_config_revision');
    }
};
