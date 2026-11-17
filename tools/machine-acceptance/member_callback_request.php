<?php
declare(strict_types=1);

namespace Keli\Acceptance;

use Illuminate\Http\Request;

/** Narrow isolated agent API admission; authentication still belongs to the HTTP kernel. */
final class MemberCallbackRequest
{
    private const ROUTES = [
        '/api/v2/server/machine/provision/result' => ['POST', 'callback'],
        '/api/v2/server/machine/nodes' => ['POST', 'machine'],
        '/api/v2/server/machine/status' => ['POST', 'machine'],
        '/api/v2/server/status' => ['POST', 'node'],
        '/api/v2/server/report' => ['POST', 'node'],
        '/api/v2/server/handshake' => ['POST', 'node'],
        '/api/v2/server/config' => ['GET', 'node'],
        '/api/v1/server/UniProxy/user' => ['GET', 'node'],
        '/api/v1/server/UniProxy/user_delta' => ['GET', 'node'],
        '/api/v1/server/UniProxy/alivelist' => ['GET', 'node'],
        '/api/v1/server/UniProxy/push' => ['POST', 'node'],
        '/api/v1/server/UniProxy/alive' => ['POST', 'node'],
        '/api/v2/server/traffic/batch' => ['GET|POST', 'node'],
    ];

    private static function need(bool $ok): void
    {
        if (!$ok) throw new \RuntimeException('isolated_agent_request_denied');
    }

    private static function id(mixed $value): int
    {
        self::need((is_int($value) || is_string($value))
            && preg_match('/^[1-9][0-9]{0,9}$/D', (string) $value) === 1
            && (int) $value <= 2147483647);
        return (int) $value;
    }

    public static function identify(Request $request): array
    {
        $path = parse_url($request->getRequestUri(), PHP_URL_PATH);
        $route = is_string($path) ? (self::ROUTES[$path] ?? null) : null;
        self::need($route !== null && $request->getPathInfo() === $path
            && in_array($request->getMethod(), explode('|', $route[0]), true)
            && strlen($request->getRequestUri()) <= 2048 && strlen($request->getContent()) <= 8192);
        $query = $request->query->all();
        self::need(array_diff(array_keys($query), ['machine_id', 'node_id', 'node_type', 'token', 'since']) === []);
        if ($request->getContent() !== '') {
            self::need($request->isMethod('POST') && $request->isJson());
            $input = json_decode($request->getContent(), true, 16, JSON_THROW_ON_ERROR);
            self::need(is_array($input));
        } else $input = [];
        foreach (['machine_id', 'node_id', 'node_type', 'token'] as $key) {
            // Do not authorize one identity and let Laravel consume another.
            self::need(!array_key_exists($key, $input) || !array_key_exists($key, $query)
                || $input[$key] === $query[$key]);
        }
        if ($route[1] === 'callback') {
            self::need($query === [] && strlen($request->getContent()) <= 2048
                && is_string($input['task_id'] ?? null) && is_string($input['installation_id'] ?? null));
            return ['kind' => 'callback', 'task_id' => $input['task_id'], 'installation_id' => $input['installation_id']];
        }
        $identity = array_replace($query, $input);
        $installation = $request->header('X-Keli-Instance-Id');
        self::need(is_string($installation) && preg_match('/^[a-f0-9]{32}$/D', $installation) === 1
            && is_string($identity['token'] ?? null) && strlen($identity['token']) > 0
            && strlen($identity['token']) <= 512);
        $machine = self::id($identity['machine_id'] ?? null);
        $node = $route[1] === 'node' ? self::id($identity['node_id'] ?? null) : null;
        if ($node !== null) self::need(($identity['node_type'] ?? null) === 'v2node');
        else self::need(!array_key_exists('node_id', $identity) && !array_key_exists('node_type', $identity));
        return ['kind' => 'report', 'machine_id' => $machine, 'node_id' => $node, 'installation_id' => $installation];
    }
}
