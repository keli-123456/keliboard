<?php

namespace App\Http\Controllers\V2\Server;

use App\Http\Controllers\Controller;
use App\Models\ServerMachine;
use App\Models\ServerMachineLoadHistory;
use App\Services\NodeRealtime\NodeRealtimeSettings;
use App\Services\ServerService;
use App\Services\SubscriptionProxy\ZeroSslCertificateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class MachineController extends Controller
{
    public function nodes(Request $request): JsonResponse
    {
        $machine = $this->authenticateMachine($request);
        if (!$machine) {
            return response()->json(['message' => 'Invalid machine credentials'], 401);
        }

        $this->touchMachine($machine);

        $nodes = ServerService::getMachineNodes($machine)
            ->map(function ($server): array {
                return [
                    'id' => (int) $server->id,
                    'code' => $server->code,
                    'type' => $server->type,
                    'name' => $server->name,
                    'updated_at' => $server->updated_at,
                ];
            })
            ->values();

        $settings = app(NodeRealtimeSettings::class);
        $wsURL = $settings->resolvedPublicUrl();
        $realtimeEnabled = $settings->enabled() && $wsURL !== '';

        return response()->json([
            'nodes' => $nodes,
            'base_config' => [
                'push_interval' => (int) admin_setting('server_push_interval', 60),
                'pull_interval' => (int) admin_setting('server_pull_interval', 60),
                'realtime' => [
                    'enabled' => $realtimeEnabled,
                    'url' => $realtimeEnabled ? $wsURL : '',
                    'ping_interval' => (int) admin_setting('server_realtime_ping_interval', 30),
                ],
            ],
            'agent' => [
                'subscription_proxy' => $this->buildSubscriptionProxyConfig($request, $machine),
            ],
        ]);
    }

    public function status(Request $request): JsonResponse
    {
        $machine = $this->authenticateMachine($request);
        if (!$machine) {
            return response()->json(['message' => 'Invalid machine credentials'], 401);
        }

        $payload = $request->input('status');
        if (!is_array($payload)) {
            $payload = $request->only(['cpu', 'mem', 'swap', 'disk', 'net', 'uptime', 'version']);
        }

        $validator = Validator::make($payload, [
            'cpu' => 'nullable|numeric|min:0|max:100',
            'mem.total' => 'nullable|integer|min:0',
            'mem.used' => 'nullable|integer|min:0',
            'swap.total' => 'nullable|integer|min:0',
            'swap.used' => 'nullable|integer|min:0',
            'disk.total' => 'nullable|integer|min:0',
            'disk.used' => 'nullable|integer|min:0',
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => 'Invalid status payload'], 422);
        }

        $status = [
            'cpu' => (float) data_get($payload, 'cpu', 0),
            'mem' => [
                'total' => (int) data_get($payload, 'mem.total', 0),
                'used' => (int) data_get($payload, 'mem.used', 0),
            ],
            'swap' => [
                'total' => (int) data_get($payload, 'swap.total', 0),
                'used' => (int) data_get($payload, 'swap.used', 0),
            ],
            'disk' => [
                'total' => (int) data_get($payload, 'disk.total', 0),
                'used' => (int) data_get($payload, 'disk.used', 0),
            ],
            'net' => is_array(data_get($payload, 'net')) ? data_get($payload, 'net') : null,
            'ip' => $this->buildIPStatus($request, $payload),
            'system' => is_array(data_get($payload, 'system')) ? data_get($payload, 'system') : null,
            'uptime' => data_get($payload, 'uptime'),
            'version' => data_get($payload, 'version'),
            'agent' => is_array(data_get($payload, 'agent')) ? data_get($payload, 'agent') : null,
            'updated_at' => now()->timestamp,
        ];
        app(ZeroSslCertificateService::class)->handleMachineStatus($machine, $status);

        $machine->forceFill([
            'last_seen_at' => now()->timestamp,
            'load_status' => $status,
        ])->save();

        ServerMachineLoadHistory::create([
            'machine_id' => (int) $machine->id,
            'cpu' => $status['cpu'],
            'mem_total' => $status['mem']['total'],
            'mem_used' => $status['mem']['used'],
            'swap_total' => $status['swap']['total'],
            'swap_used' => $status['swap']['used'],
            'disk_total' => $status['disk']['total'],
            'disk_used' => $status['disk']['used'],
            'load_status' => $status,
        ]);

        ServerMachineLoadHistory::where('machine_id', (int) $machine->id)
            ->where('created_at', '<', now()->subDays(7))
            ->delete();

        return response()->json(['data' => true]);
    }

    private function buildIPStatus(Request $request, array $payload): array
    {
        $ip = is_array(data_get($payload, 'ip')) ? data_get($payload, 'ip') : [];
        $panelSeen = trim((string) $request->ip());
        if ($panelSeen !== '') {
            $ip['panel_seen'] = $panelSeen;
        }

        return $ip;
    }

    private function authenticateMachine(Request $request): ?ServerMachine
    {
        $machineId = $request->input('machine_id');
        $token = trim((string) $request->input('token', ''));
        if (!is_scalar($machineId) || (int) $machineId <= 0 || $token === '') {
            return null;
        }

        $machine = ServerMachine::query()
            ->whereKey((int) $machineId)
            ->where('is_active', true)
            ->first();
        if (!$machine || !hash_equals((string) $machine->token, $token)) {
            return null;
        }

        return $machine;
    }

    private function touchMachine(ServerMachine $machine): void
    {
        $machine->forceFill(['last_seen_at' => now()->timestamp])->save();
    }

    private function buildSubscriptionProxyConfig(Request $request, ServerMachine $machine): array
    {
        $globalEnabled = (bool) admin_setting('subscription_proxy_enable', false);
        $machineEnabled = (bool) $machine->getAttribute('subproxy_enabled');
        if (!$globalEnabled || !$machineEnabled) {
            return ['enabled' => false];
        }

        $upstreamBaseURL = $this->resolvePanelBaseURL($request);
        $subscribePath = trim((string) admin_setting('subscribe_path', 's'), '/');
        if ($subscribePath === '') {
            $subscribePath = 's';
        }

        return [
            'enabled' => true,
            'site_id' => $this->resolveSubscriptionProxySiteId($upstreamBaseURL),
            'upstream_base_url' => $upstreamBaseURL,
            'subscribe_path' => $subscribePath,
            'https_listen' => $this->listenAddress(
                (int) ($machine->subproxy_https_port ?: admin_setting('subscription_proxy_https_port', 443)),
                443
            ),
            'http_listen' => $this->listenAddress(
                (int) ($machine->subproxy_http_port ?: admin_setting('subscription_proxy_http_port', 80)),
                80
            ),
            'certificate_domain' => $this->resolveCertificateDomain($request, $machine),
            'challenge_dir' => (string) admin_setting('subscription_proxy_challenge_dir', '/etc/v2node/subproxy/challenges'),
            'cert_file' => (string) admin_setting('subscription_proxy_cert_file', '/etc/v2node/subproxy/cert.pem'),
            'key_file' => (string) admin_setting('subscription_proxy_key_file', '/etc/v2node/subproxy/key.pem'),
            'zerossl' => $this->buildZeroSslAgentConfig($machine),
            'allow_http_fallback' => (bool) admin_setting('subscription_proxy_allow_http_fallback', false),
            'max_response_bytes' => max(1024 * 1024, (int) admin_setting('subscription_proxy_max_response_bytes', 10485760)),
        ];
    }

    private function resolvePanelBaseURL(Request $request): string
    {
        $baseURL = rtrim((string) admin_setting('app_url', ''), '/');
        if ($baseURL !== '') {
            return $baseURL;
        }

        return rtrim($request->getSchemeAndHttpHost(), '/');
    }

    private function resolveSubscriptionProxySiteId(string $baseURL): string
    {
        $configured = trim((string) admin_setting('subscription_proxy_site_id', ''));
        if ($configured !== '') {
            $siteId = $this->sanitizeSiteId($configured);
            if ($siteId !== '') {
                return $siteId;
            }
        }

        $host = (string) parse_url($baseURL, PHP_URL_HOST);
        $siteId = $this->sanitizeSiteId($host);
        if ($siteId !== '') {
            return $siteId;
        }

        return substr(sha1($baseURL), 0, 12);
    }

    private function sanitizeSiteId(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9._-]+/', '-', $value) ?: '';
        return trim($value, '.-_');
    }

    private function listenAddress(int $port, int $fallback): string
    {
        $port = $port >= 1 && $port <= 65535 ? $port : $fallback;
        return '0.0.0.0:' . $port;
    }

    private function resolveCertificateDomain(Request $request, ServerMachine $machine): string
    {
        $configured = trim((string) ($machine->subproxy_cert_domain ?? ''));
        if ($configured !== '') {
            return $configured;
        }

        $state = is_array($machine->subproxy_cert_state) ? $machine->subproxy_cert_state : [];
        $domain = trim((string) ($state['domain'] ?? ''));
        if ($domain !== '') {
            return $domain;
        }

        return (string) $request->ip();
    }

    private function buildZeroSslAgentConfig(ServerMachine $machine): array
    {
        $state = is_array($machine->subproxy_cert_state) ? $machine->subproxy_cert_state : [];
        if (empty($state)) {
            return ['status' => 'idle'];
        }

        $config = [
            'status' => (string) ($state['status'] ?? 'idle'),
            'certificate_id' => (string) ($state['certificate_id'] ?? ''),
            'validation_path' => (string) ($state['validation_path'] ?? ''),
            'validation_content' => $state['validation_content'] ?? null,
            'expires_at' => (string) ($state['expires_at'] ?? ''),
        ];

        if (($state['status'] ?? '') === 'issued') {
            $config['certificate_pem'] = (string) ($state['certificate_pem'] ?? '');
            $config['ca_bundle_pem'] = (string) ($state['ca_bundle_pem'] ?? '');
        }

        return $config;
    }
}
