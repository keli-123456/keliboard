<?php

namespace App\Http\Middleware;

use App\Models\AdminAuditLog;
use Closure;

class RequestLog
{
    private const AUDIT_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];
    private const SENSITIVE_KEYS = ['password', 'token', 'secret', 'key', 'api_key'];

    public function handle($request, Closure $next)
    {
        $response = $next($request);

        if (!in_array(strtoupper($request->method()), self::AUDIT_METHODS, true)) {
            return $response;
        }

        try {
            $user = $request->user();
            if (!$user || (!$user->is_admin && !$user->is_staff)) {
                return $response;
            }

            AdminAuditLog::query()->insert([
                'admin_id' => $user->id,
                'action' => $this->resolveAction($request->path()),
                'method' => strtoupper($request->method()),
                'uri' => $request->getRequestUri(),
                'request_data' => json_encode(
                    $this->sanitizePayload($request->all()),
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR
                ),
                'ip' => $request->getClientIp(),
                'created_at' => time(),
                'updated_at' => time(),
            ]);
        } catch (\Throwable $e) {
            \Log::warning('Audit log write failed: ' . $e->getMessage());
        }

        return $response;
    }

    private function resolveAction(string $path): string
    {
        $path = preg_replace('#^api/v[12]/[^/]+/#', '', $path) ?? $path;
        $path = str_replace('-', '_', trim($path, '/'));

        if ($path === '') {
            return 'system.unknown';
        }

        $segments = array_values(array_filter(explode('/', $path)));
        $method = array_pop($segments) ?: 'unknown';
        $resource = implode('_', $segments);

        return ($resource !== '' ? $resource : 'system') . '.' . $method;
    }

    private function sanitizePayload(mixed $value, int $depth = 0): mixed
    {
        if ($depth >= 6) {
            return '[depth-truncated]';
        }

        if ($value instanceof \Illuminate\Http\UploadedFile || $value instanceof \Symfony\Component\HttpFoundation\File\UploadedFile) {
            return [
                'name' => $value->getClientOriginalName(),
                'mime' => $value->getClientMimeType(),
                'size' => $value->getSize(),
            ];
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        if (is_array($value)) {
            $result = [];
            foreach ($value as $key => $item) {
                $normalizedKey = is_string($key) ? strtolower($key) : (string) $key;
                $isSensitive = false;
                foreach (self::SENSITIVE_KEYS as $sensitiveKey) {
                    if (str_contains($normalizedKey, $sensitiveKey)) {
                        $isSensitive = true;
                        break;
                    }
                }
                $result[$key] = $isSensitive ? '[REDACTED]' : $this->sanitizePayload($item, $depth + 1);
            }
            return $result;
        }

        if (is_string($value)) {
            return mb_strlen($value) > 2000 ? mb_substr($value, 0, 2000) . '...(truncated)' : $value;
        }

        if (is_numeric($value) || is_bool($value) || $value === null) {
            return $value;
        }

        if ($value instanceof \JsonSerializable) {
            return $this->sanitizePayload($value->jsonSerialize(), $depth + 1);
        }

        if ($value instanceof \Stringable) {
            return (string) $value;
        }

        if (is_object($value)) {
            return ['__class__' => get_class($value)];
        }

        return (string) $value;
    }
}
