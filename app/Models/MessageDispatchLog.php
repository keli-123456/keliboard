<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MessageDispatchLog extends Model
{
    protected $table = 'v2_message_dispatch_log';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'context' => 'array',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
        'noted_at' => 'timestamp',
    ];

    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';
    public const STATUS_SUPPRESSED = 'suppressed';

    public const FAILURE_PERMANENT = 'permanent_failure';
    public const FAILURE_TEMPORARY = 'temporary_failure';
    public const FAILURE_PROVIDER = 'provider_issue';
    public const FAILURE_RATE_LIMIT = 'rate_limit';
    public const FAILURE_TIMEOUT = 'timeout';

    public const HEALTH_HEALTHY = 'healthy';
    public const HEALTH_DEGRADED = 'degraded';
    public const HEALTH_UNHEALTHY = 'unhealthy';
    public const HEALTH_UNKNOWN = 'unknown';

    public function rule()
    {
        return $this->belongsTo(MarketingRule::class, 'rule_id', 'id');
    }

    public function template()
    {
        return $this->belongsTo(MarketingTemplate::class, 'template_id', 'id');
    }
}
