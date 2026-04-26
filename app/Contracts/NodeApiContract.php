<?php

declare(strict_types=1);

namespace App\Contracts;

final class NodeApiContract
{
    public const VERSION = '2026-04-26';

    public const V1_UNIPROXY_SEGMENT = 'UniProxy';
    public const V1_UNIPROXY_PREFIX = 'server/UniProxy';
    public const V2_SERVER_PREFIX = 'server';
    public const V2_SERVER_MACHINE_PREFIX = 'server/machine';

    public const ENDPOINT_CONFIG = 'config';
    public const ENDPOINT_USER = 'user';
    public const ENDPOINT_USER_DELTA = 'user_delta';
    public const ENDPOINT_PUSH = 'push';
    public const ENDPOINT_ALIVE = 'alive';
    public const ENDPOINT_ALIVE_LIST = 'alivelist';
    public const ENDPOINT_STATUS = 'status';
    public const ENDPOINT_HANDSHAKE = 'handshake';
    public const ENDPOINT_REPORT = 'report';
    public const ENDPOINT_MACHINE_NODES = 'nodes';
    public const ENDPOINT_MACHINE_STATUS = 'status';

    public const HEADER_RESPONSE_FORMAT = 'X-Response-Format';
    public const RESPONSE_FORMAT_MSGPACK = 'msgpack';
    public const CONTENT_TYPE_MSGPACK = 'application/x-msgpack';

    public static function v1UniProxyPath(string $endpoint): string
    {
        return self::V1_UNIPROXY_PREFIX . '/' . ltrim($endpoint, '/');
    }

    public static function v2ServerPath(string $endpoint): string
    {
        return self::V2_SERVER_PREFIX . '/' . ltrim($endpoint, '/');
    }

    public static function v2ServerMachinePath(string $endpoint): string
    {
        return self::V2_SERVER_MACHINE_PREFIX . '/' . ltrim($endpoint, '/');
    }
}
