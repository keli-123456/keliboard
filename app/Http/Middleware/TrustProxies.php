<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as Middleware;
use Illuminate\Http\Request;

class TrustProxies extends Middleware
{
    /**
     * 可信代理列表
     * @var array<int, string>|string|null
     */
    protected $proxies = [
        "173.245.48.0/20",
        "103.21.244.0/22",
        "103.22.200.0/22",
        "103.31.4.0/22",
        "141.101.64.0/18",
        "108.162.192.0/18",
        "190.93.240.0/20",
        "188.114.96.0/20",
        "197.234.240.0/22",
        "198.41.128.0/17",
        "162.158.0.0/15",
        "104.16.0.0/13",
        "104.24.0.0/14",
        "172.64.0.0/13",
        "131.0.72.0/22",
        "10.0.0.0/8",
        "172.16.0.0/12",
        "192.168.0.0/16",
        "169.254.0.0/16",
        "127.0.0.0/8",
    ];

    public function __construct()
    {
        $extra = env('XBOARD_TRUSTED_PROXIES', env('TRUSTED_PROXIES', ''));
        if (is_string($extra)) {
            $raw = trim($extra);
            if ($raw === '*') {
                $this->proxies = '*';
                return;
            }
            if ($raw !== '') {
                $items = preg_split('/[,\s]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
                $items = array_values(array_filter(array_map(fn($v) => trim((string) $v), $items)));
                if ($items) {
                    $current = is_array($this->proxies) ? $this->proxies : [];
                    $this->proxies = array_values(array_unique(array_merge($current, $items)));
                }
            }
        }
    }

    /**
     * 代理头映射
     * @var int
     */
    protected $headers =
    Request::HEADER_X_FORWARDED_FOR |
    Request::HEADER_X_FORWARDED_HOST |
    Request::HEADER_X_FORWARDED_PORT |
    Request::HEADER_X_FORWARDED_PROTO |
    Request::HEADER_X_FORWARDED_AWS_ELB;
}
