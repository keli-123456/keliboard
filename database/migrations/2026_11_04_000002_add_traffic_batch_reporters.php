<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('v2_traffic_batch') && !Schema::hasColumn('v2_traffic_batch', 'reporter_key')) {
            Schema::table('v2_traffic_batch', function (Blueprint $table): void { $table->string('reporter_key', 96)->nullable(); });
        }
        if (!Schema::hasTable('v2_server_instance_traffic')) {
            Schema::create('v2_server_instance_traffic', function (Blueprint $table): void {
                $table->id(); $table->unsignedInteger('server_id'); $table->string('reporter_key', 96);
                $table->unsignedInteger('record_at'); $table->unsignedBigInteger('upload')->default(0);
                $table->unsignedBigInteger('download')->default(0); $table->unsignedInteger('updated_at');
                $table->unique(['server_id', 'reporter_key', 'record_at'], 'instance_traffic_day_unique');
            });
        }
    }
    public function down(): void
    {
        Schema::dropIfExists('v2_server_instance_traffic');
        if (Schema::hasColumn('v2_traffic_batch', 'reporter_key')) Schema::table('v2_traffic_batch', fn (Blueprint $table) => $table->dropColumn('reporter_key'));
    }
};
