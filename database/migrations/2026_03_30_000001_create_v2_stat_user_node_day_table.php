<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('v2_stat_user_node_day')) {
            return;
        }

        Schema::create('v2_stat_user_node_day', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('user_id');
            $table->integer('server_id');
            $table->char('server_type', 16)->default('');
            $table->string('server_name')->default('');
            $table->decimal('server_rate', 10, 2)->default(1);
            $table->bigInteger('u');
            $table->bigInteger('d');
            $table->char('record_type', 1)->comment('d day m month');
            $table->integer('record_at');
            $table->integer('created_at');
            $table->integer('updated_at');

            $table->unique(
                ['user_id', 'server_id', 'server_rate', 'record_at', 'record_type'],
                'uq_stat_user_node_day'
            );
            $table->index(['user_id', 'record_at'], 'idx_stat_user_node_day_user_record');
            $table->index('record_at', 'idx_stat_user_node_day_record_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_stat_user_node_day');
    }
};
