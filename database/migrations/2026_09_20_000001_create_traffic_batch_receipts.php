<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('v2_traffic_batch', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->bigIncrements('id');
            $table->unsignedInteger('node_id');
            $table->string('node_type', 32);
            $table->char('report_id', 32);
            $table->char('content_hash', 64);
            $table->char('prepared_hash', 64);
            $table->longText('payload')->nullable();
            $table->unsignedInteger('created_at');
            $table->unsignedInteger('processed_at')->nullable();
            $table->unsignedInteger('sync_finished_at')->nullable();
            $table->unsignedInteger('retry_at')->default(0);
            $table->unique(['node_id', 'report_id'], 'uq_traffic_batch_identity');
            $table->index(['sync_finished_at', 'retry_at', 'id'], 'idx_traffic_batch_pending');
        });
    }

    public function down(): void
    {
        // Receipts are dedup tombstones, not disposable cache, even after application.
        throw new RuntimeException('Traffic receipts require an explicit settled-data migration before removal.');
    }
};
