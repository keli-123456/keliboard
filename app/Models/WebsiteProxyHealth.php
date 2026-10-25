<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WebsiteProxyHealth extends Model
{
    public const FRESH_SECONDS = 180;

    protected $table = 'v2_website_proxy_health';

    protected $guarded = ['id'];

    protected $casts = [
        'machine_id' => 'integer',
        'site_id' => 'integer',
        'tls_verified' => 'boolean',
        'http_status' => 'integer',
        'context_http_status' => 'integer',
        'latency_ms' => 'integer',
        'consecutive_successes' => 'integer',
        'consecutive_failures' => 'integer',
        'last_checked_at' => 'integer',
        'last_success_at' => 'integer',
    ];

    public function eligibleFor(array $target): bool
    {
        $now = time();

        return $this->status === 'ok'
            && $this->tls_verified
            && $this->http_status === 200
            && $this->context_http_status === 200
            && $this->machine_id === $target['machine_id']
            && $this->site_id === $target['site_id']
            && hash_equals((string) $this->route_fingerprint, $target['route_fingerprint'])
            && $this->last_checked_at > $now - self::FRESH_SECONDS
            && $this->last_checked_at <= $now + 5
            && $this->last_success_at > $now - self::FRESH_SECONDS
            && $this->last_success_at <= $now + 5;
    }
}
