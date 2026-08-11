<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdminOperationTaskItem extends Model
{
    protected $table = 'v2_admin_operation_task_item';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];

    protected $casts = [
        'payload' => 'array',
        'result' => 'array',
        'attempt_count' => 'integer',
        'started_at' => 'timestamp',
        'finished_at' => 'timestamp',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];

    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED = 'failed';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_CANCELLED = 'cancelled';

    public function task(): BelongsTo
    {
        return $this->belongsTo(AdminOperationTask::class, 'task_id');
    }
}
