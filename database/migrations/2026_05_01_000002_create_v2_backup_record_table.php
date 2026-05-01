<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('v2_backup_record')) {
            return;
        }

        Schema::create('v2_backup_record', function (Blueprint $table): void {
            $table->integer('id', true);
            $table->string('type', 32)->default('database')->index();
            $table->string('status', 24)->default('running')->index();
            $table->string('disk', 32)->default('local');
            $table->string('filename', 255);
            $table->string('path', 1024)->nullable();
            $table->string('remote_path', 1024)->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->string('checksum', 64)->nullable();
            $table->json('options')->nullable();
            $table->text('error')->nullable();
            $table->integer('started_at')->nullable()->index();
            $table->integer('finished_at')->nullable()->index();
            $table->integer('created_at');
            $table->integer('updated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_backup_record');
    }
};
