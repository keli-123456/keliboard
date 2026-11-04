<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('v2_server_machine_binding')) return;
        Schema::create('v2_server_machine_binding', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('server_id');
            $table->unsignedInteger('machine_id');
            $table->unsignedInteger('created_at');
            $table->unique(['server_id', 'machine_id']);
            $table->index('machine_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_server_machine_binding');
    }
};
