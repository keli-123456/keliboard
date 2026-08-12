<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiDiagnosticDisposition extends Model
{
    protected $table = 'v2_ai_diagnostic_disposition';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];

    public const STATUS_OPEN = 'open';
    public const STATUS_RESOLVED = 'resolved';
    public const STATUS_FALSE_POSITIVE = 'false_positive';
    public const STATUS_IGNORED = 'ignored';
    public const STATUSES = [
        self::STATUS_OPEN,
        self::STATUS_RESOLVED,
        self::STATUS_FALSE_POSITIVE,
        self::STATUS_IGNORED,
    ];

    protected $casts = [
        'report_id' => 'integer',
        'subject_id' => 'integer',
        'cooling_until' => 'integer',
        'admin_id' => 'integer',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];
}
