<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AdminOperationTask extends Model
{
    protected $table = 'v2_admin_operation_task';
    protected $dateFormat = 'U';
    protected $guarded = [];
    public $incrementing = false;
    protected $keyType = 'string';

    protected $casts = [
        'payload' => 'array',
        'context' => 'array',
        'total' => 'integer',
        'completed' => 'integer',
        'succeeded' => 'integer',
        'failed' => 'integer',
        'skipped' => 'integer',
        'cancelled' => 'integer',
        'cancel_requested_at' => 'timestamp',
        'started_at' => 'timestamp',
        'finished_at' => 'timestamp',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];

    public const STATUS_QUEUED = 'queued';
    public const STATUS_RUNNING = 'running';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_PARTIAL = 'partial';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_INTERRUPTED = 'interrupted';

    public const TERMINAL_STATUSES = [
        self::STATUS_SUCCEEDED,
        self::STATUS_PARTIAL,
        self::STATUS_FAILED,
        self::STATUS_CANCELLED,
        self::STATUS_INTERRUPTED,
    ];

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(AdminOperationTaskItem::class, 'task_id');
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL_STATUSES, true);
    }
}
