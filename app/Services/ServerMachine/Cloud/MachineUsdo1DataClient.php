<?php

declare(strict_types=1);

namespace App\Services\ServerMachine\Cloud;

final class MachineUsdo1DataClient
{
    private const SOCKETS = ['receipt' => '/run/keli-usdo1/data-receipt.sock', 'worker' => '/run/keli-usdo1/data-worker.sock'];

    public function __construct(private string $lane, private string $key, private ?\Closure $transport = null)
    {
        if (!isset(self::SOCKETS[$lane]) || !MachineUsdo1DataBroker::validKey($key)) {
            throw new CloudException('usdo1_data_client_unconfigured');
        }
    }

    public static function fromEnvironment(string $lane): self
    {
        return new self($lane, (string) getenv('KELI_USDO1_DATA_TOKEN'));
    }

    public function receipt(string $task, string $installation, string $secret, string $state): bool
    {
        if ($this->lane !== 'receipt') throw new CloudException('usdo1_data_operation_denied');
        $response = $this->call(MachineUsdo1DataBroker::RECEIPT, ['task_id' => $task, 'installation_id' => $installation,
            'receipt_key' => $secret, 'state' => $state], 8);
        if ($response !== ['ok' => true]) throw new \RuntimeException('usdo1_data_response_invalid');
        return true;
    }

    public function tick(): array
    {
        if ($this->lane !== 'worker') throw new CloudException('usdo1_data_operation_denied');
        $response = $this->call(MachineUsdo1DataBroker::WORKER, [], 55);
        if (($response['ok'] ?? null) !== true || count($response) !== 2 || !is_array($view = $response['result'] ?? null)
            || MachineUsdo1DataBroker::result($view) !== $view) {
            throw new \RuntimeException('usdo1_data_response_invalid');
        }
        return $view;
    }

    private function call(string $path, array $input, int $timeout): array
    {
        $socket = self::SOCKETS[$this->lane];
        $auth = 'Bearer ' . $this->key;
        $body = json_encode((object) $input, JSON_THROW_ON_ERROR);
        [$status, $result] = ($this->transport ?? $this->unixHttp(...))($socket, $path, $auth, $body, $timeout);
        if ($status === 401) throw new CloudException('usdo1_data_operation_denied');
        if ($status !== 200 || strlen($result) > 2048) throw new \RuntimeException('usdo1_data_unavailable');
        $decoded = json_decode($result, true, 4, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) throw new \RuntimeException('usdo1_data_response_invalid');
        return $decoded;
    }

    private function unixHttp(string $socket, string $path, string $auth, string $body, int $timeout): array
    {
        if (PHP_OS_FAMILY !== 'Linux' || is_link($socket) || @filetype($socket) !== 'socket') {
            throw new \RuntimeException('usdo1_data_unavailable');
        }
        $curl = curl_init('http://localhost' . $path);
        if ($curl === false) throw new \RuntimeException('usdo1_data_unavailable');
        $result = '';
        $options = [
            CURLOPT_UNIX_SOCKET_PATH => $socket, CURLOPT_PROXY => '', CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_TIMEOUT => $timeout, CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => ['Authorization: ' . $auth, 'Content-Type: application/json'],
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$result): int {
                if (strlen($result) + strlen($chunk) > 2048) return 0;
                $result .= $chunk;
                return strlen($chunk);
            },
        ];
        if (!curl_setopt_array($curl, $options) || curl_exec($curl) === false) throw new \RuntimeException('usdo1_data_unavailable');
        return [curl_getinfo($curl, CURLINFO_RESPONSE_CODE), $result];
    }
}
