<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\BackupRecord;
use App\Services\Backup\BackupService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class BackupServiceTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpInMemoryDatabase();
        app()->instance('db.schema', $this->database->getConnection()->getSchemaBuilder());
        app()->instance('files', new Filesystem());
        $this->createBackupRecordTable();
    }

    public function test_record_restore_drill_stores_latest_summary_without_losing_options(): void
    {
        $record = $this->createBackupRecord([
            'options' => ['database_connection' => 'mysql'],
        ]);

        $result = (new BackupService())->recordRestoreDrill($record->id, [
            'status' => 'passed',
            'environment' => 'staging',
            'note' => str_repeat('A', 1100),
            'operator' => str_repeat('B', 130),
        ]);

        $record->refresh();
        $drills = $record->options['restore_drills'] ?? [];

        $this->assertSame('mysql', $record->options['database_connection']);
        $this->assertCount(1, $drills);
        $this->assertSame('passed', $drills[0]['status']);
        $this->assertSame('staging', $drills[0]['environment']);
        $this->assertSame(1000, strlen($drills[0]['note']));
        $this->assertSame(120, strlen($drills[0]['operator']));
        $this->assertSame($drills[0]['id'], $result['record']['latest_restore_drill']['id']);
        $this->assertSame($drills[0]['id'], $result['drill']['id']);
    }

    public function test_record_restore_drill_rejects_incomplete_backup(): void
    {
        $record = $this->createBackupRecord([
            'status' => BackupRecord::STATUS_RUNNING,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Only completed backups can record restore drills');

        (new BackupService())->recordRestoreDrill($record->id, [
            'status' => 'incomplete',
            'environment' => 'local',
        ]);
    }

    private function createBackupRecord(array $overrides = []): BackupRecord
    {
        $now = time();

        return BackupRecord::create(array_merge([
            'type' => BackupRecord::TYPE_DATABASE,
            'status' => BackupRecord::STATUS_SUCCEEDED,
            'disk' => 'local',
            'filename' => '2026-05-02_03-30-00_xboard_database_backup.sql.gz',
            'path' => 'backup/2026-05-02_03-30-00_xboard_database_backup.sql.gz',
            'remote_path' => null,
            'size' => 128,
            'checksum' => str_repeat('a', 64),
            'options' => [],
            'error' => null,
            'started_at' => $now - 10,
            'finished_at' => $now,
            'created_at' => $now - 10,
            'updated_at' => $now,
        ], $overrides));
    }

    private function createBackupRecordTable(): void
    {
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
}
