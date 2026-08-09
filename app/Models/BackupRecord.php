<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BackupRecord extends Model
{
    protected $table = 'v2_backup_record';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];

    protected $casts = [
        'options' => 'array',
        'size' => 'integer',
        'started_at' => 'timestamp',
        'finished_at' => 'timestamp',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];

    public const TYPE_DATABASE = 'database';

    public const STATUS_QUEUED = 'queued';
    public const STATUS_RUNNING = 'running';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_UPLOADED = 'uploaded';
    public const STATUS_FAILED = 'failed';
}
