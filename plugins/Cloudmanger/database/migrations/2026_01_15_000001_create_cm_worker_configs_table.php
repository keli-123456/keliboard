<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('cm_worker_configs')) {
            Schema::create('cm_worker_configs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->string('worker', 64);
                $table->longText('config_encrypted');
                $table->string('note', 255)->nullable();
                $table->timestamps();

                $table->unique(['user_id', 'worker']);
                $table->index('user_id');
                $table->index('worker');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cm_worker_configs');
    }
};

