<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('v2_ticket_message_attachment')) {
            return;
        }

        Schema::create('v2_ticket_message_attachment', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('ticket_id')->index();
            $table->integer('ticket_message_id')->index();
            $table->integer('user_id')->index();
            $table->string('disk', 32)->default('local');
            $table->string('path', 255);
            $table->string('mime', 64)->default('image/webp');
            $table->integer('size')->default(0);
            $table->integer('width')->nullable();
            $table->integer('height')->nullable();
            $table->integer('created_at');
            $table->integer('updated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_ticket_message_attachment');
    }
};

