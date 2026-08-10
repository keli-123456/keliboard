<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DomainHealth extends Model
{
    protected $table = 'v2_domain_health';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];

    public const SOURCE_SITE = 'site';
    public const SOURCE_AGENT = 'agent';
    public const SOURCE_NAVIGATION = 'navigation';
    public const SOURCE_SYSTEM = 'system';

    public const STATUS_UNKNOWN = 'unknown';
    public const STATUS_HEALTHY = 'healthy';
    public const STATUS_WARNING = 'warning';
    public const STATUS_DOWN = 'down';

    protected $casts = [
        'dns_addresses' => 'array',
        'monitored' => 'boolean',
        'alert_active' => 'boolean',
    ];
}
