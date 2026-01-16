<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('cm_worker_scripts')) {
            Schema::create('cm_worker_scripts', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->string('worker', 64);
                $table->string('script_id', 128);
                $table->longText('content_encrypted');
                $table->string('note', 255)->nullable();
                $table->timestamps();

                $table->unique(['user_id', 'worker', 'script_id']);
                $table->index('user_id');
                $table->index('worker');
                $table->index('script_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cm_worker_scripts');
    }
};

