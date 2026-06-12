<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('v2_server_machine_release')) {
            return;
        }

        Schema::create('v2_server_machine_release', function (Blueprint $table): void {
            $table->id();
            $table->string('component', 32)->comment('kelinode-rs or keli-core-rs');
            $table->string('version', 64)->comment('release version, for example v0.1.308');
            $table->string('platform', 32)->default('linux-x86_64')->comment('release platform');
            $table->string('manifest_path', 512)->comment('local storage manifest path');
            $table->string('archive_path', 512)->comment('local storage archive path');
            $table->char('sha256', 64)->comment('archive sha256');
            $table->unsignedBigInteger('size')->default(0)->comment('archive bytes');
            $table->boolean('is_default')->default(false)->comment('preferred local release');
            $table->string('status', 16)->default('active')->comment('active or disabled');
            $table->timestamps();

            $table->unique(['component', 'version', 'platform'], 'uniq_server_machine_release_target');
            $table->index(['component', 'platform', 'is_default'], 'idx_server_machine_release_default');
            $table->index(['component', 'platform', 'status'], 'idx_server_machine_release_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_server_machine_release');
    }
};

