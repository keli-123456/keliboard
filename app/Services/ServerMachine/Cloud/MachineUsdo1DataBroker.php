<?php

declare(strict_types=1);

namespace App\Services\ServerMachine\Cloud;

use Illuminate\Support\Str;

/** The server pins its scope; request bodies cannot select a service, grant or operation. */
final class MachineUsdo1DataBroker
{
    public const RECEIPT = '/receipt';
    public const WORKER = '/tick';

    public function __construct(private MachineUsdo1LaunchScope $scope, private string $receiptKey, private string $workerKey,
        private \Closure $acceptReceipt, private \Closure $advance) {
        if (!self::validKey($receiptKey) || !self::validKey($workerKey) || hash_equals($receiptKey, $workerKey)) {
            throw new CloudException('usdo1_data_broker_unconfigured');
        }
    }

    public static function validKey(string $key): bool
    {
        return preg_match('/\A[a-f0-9]{64}\z/', $key) === 1;
    }

    public static function result(array $view): array
    {
        $out = [];
        foreach (['status', 'runtime'] as $key) {
            if (is_string($view[$key] ?? null) && preg_match('/\A[a-z_]{1,32}\z/', $view[$key])) $out[$key] = $view[$key];
        }
        foreach (['expired', 'revoked', 'stop_requested'] as $key) {
            if (is_bool($view[$key] ?? null)) $out[$key] = $view[$key];
        }
        if (!isset($out['status'])) throw new \RuntimeException('usdo1_data_response_invalid');
        return $out;
    }

    /** $lane is configured by the private listener, never taken from an HTTP header or body. */
    public function respond(string $lane, string $method, string $path, string $authorization, string $raw): array
    {
        $expected = match ($lane) { 'receipt' => self::RECEIPT, 'worker' => self::WORKER, default => null };
        if ($path !== $expected) return [404, ['ok' => false]];
        if ($method !== 'POST') return [405, ['ok' => false]];
        $key = $lane === 'receipt' ? $this->receiptKey : $this->workerKey;
        if (!hash_equals('Bearer ' . $key, $authorization)) return [401, ['ok' => false]];
        if (strlen($raw) > 2048) return [413, ['ok' => false]];
        try { $data = json_decode($raw, false, 4, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { return [400, ['ok' => false]]; }
        if (!$data instanceof \stdClass) return [400, ['ok' => false]];
        $input = (array) $data;
        if ($lane === 'receipt') {
            if (count($input) !== 4 || !is_string($id = $input['task_id'] ?? null) || !Str::isUuid($id)
                || $id !== strtolower($id) || !is_string($installation = $input['installation_id'] ?? null)
                || !preg_match('/\A[a-f0-9]{32}\z/', $installation) || !is_string($secret = $input['receipt_key'] ?? null)
                || !self::validKey($secret) || !in_array($state = $input['state'] ?? null, ['succeeded', 'failed'], true)) {
                return [400, ['ok' => false]];
            }
        } elseif ($input !== []) {
            return [400, ['ok' => false]];
        }
        try {
            if ($lane === 'receipt') {
                $accepted = ($this->acceptReceipt)($this->scope, $id, $installation, $secret, $state);
                return $accepted === true ? [200, ['ok' => true]] : [401, ['ok' => false]];
            }
            return [200, ['ok' => true, 'result' => self::result(($this->advance)($this->scope))]];
        } catch (CloudException) {
            return [401, ['ok' => false]];
        } catch (\Throwable) {
            return [503, ['ok' => false]];
        }
    }
}
