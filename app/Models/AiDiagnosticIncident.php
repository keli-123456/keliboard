<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiDiagnosticIncident extends Model
{
    protected $table = 'v2_ai_diagnostic_incident';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];

    public const STATUS_OPEN = 'open';
    public const STATUS_ASSIGNED = 'assigned';
    public const STATUS_RECOVERED = 'recovered';
    public const STATUS_RESOLVED = 'resolved';
    public const STATUS_FALSE_POSITIVE = 'false_positive';
    public const STATUS_IGNORED = 'ignored';
    public const STATUSES = [
        self::STATUS_OPEN,
        self::STATUS_ASSIGNED,
        self::STATUS_RECOVERED,
        self::STATUS_RESOLVED,
        self::STATUS_FALSE_POSITIVE,
        self::STATUS_IGNORED,
    ];
    public const ACTIVE_STATUSES = [self::STATUS_OPEN, self::STATUS_ASSIGNED];

    protected $casts = [
        'site_id' => 'integer',
        'subject_id' => 'integer',
        'first_report_id' => 'integer',
        'last_report_id' => 'integer',
        'occurrence_count' => 'integer',
        'recurrence_count' => 'integer',
        'assignee_id' => 'integer',
        'due_at' => 'integer',
        'first_seen_at' => 'integer',
        'last_seen_at' => 'integer',
        'resolved_at' => 'integer',
        'last_notified_at' => 'integer',
        'last_notification_channels' => 'array',
        'latest_evidence' => 'array',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(AiDiagnosticIncidentLog::class, 'incident_id');
    }
}
