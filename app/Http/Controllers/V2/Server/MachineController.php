<?php

namespace App\Http\Controllers\V2\Server;

use App\Http\Controllers\Controller;
use App\Models\ServerMachine;
use App\Models\ServerMachineLoadHistory;
use App\Services\NodeRealtime\NodeRealtimeSettings;
use App\Services\ServerMachine\MachineReleaseDistributionService;
use App\Services\ServerService;
use App\Services\ServerTlsCertificateService;
use App\Services\SubscriptionProxy\ZeroSslCertificateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class MachineController extends Controller
{
    public function nodes(Request $request): JsonResponse
    {
        $machine = $this->authenticateMachine($request, true);
        if (!$machine) {
            return response()->json(['message' => 'Invalid machine credentials'], 401);
        }

        if (!$machine->is_active) {
            return response()->json([
                'nodes' => [],
                'base_config' => $this->buildBaseConfig(),
                'agent' => [
                    'subscription_proxy' => ['enabled' => false],
                ],
                'machine' => [
                    'is_active' => false,
                ],
            ]);
        }

        $this->touchMachine($machine);

        $nodes = ServerService::getMachineNodes($machine)
            ->map(function ($server): array {
                return [
                    'id' => (int) $server->id,
                    'code' => $server->code,
                    'type' => $server->type,
                    'protocol' => $this->machineNodeProtocol($server),
                    'node_type' => $server->type,
                    'name' => $server->name,
                    'updated_at' => $server->updated_at,
                ];
            })
            ->values();

        return response()->json([
            'nodes' => $nodes,
            'base_config' => $this->buildBaseConfig(),
            'agent' => [
                'subscription_proxy' => $this->buildSubscriptionProxyConfig($request, $machine),
            ],
        ]);
    }

    private function machineNodeProtocol($server): string
    {
        $type = (string) $server->type;
        if ($type === 'hysteria') {
            $settings = is_array($server->protocol_settings) ? $server->protocol_settings : [];
            return (int) data_get($settings, 'version', 2) === 2 ? 'hysteria2' : 'hysteria';
        }

        return $type;
    }

    public function status(Request $request): JsonResponse
    {
        $machine = $this->authenticateMachine($request, true);
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
            'runtime' => is_array(data_get($payload, 'runtime')) ? data_get($payload, 'runtime') : null,
            'core' => is_array(data_get($payload, 'core')) ? data_get($payload, 'core') : null,
            'hy2_port_forward' => is_array(data_get($payload, 'hy2_port_forward')) ? data_get($payload, 'hy2_port_forward') : null,
            'mieru_port_forward' => is_array(data_get($payload, 'mieru_port_forward')) ? data_get($payload, 'mieru_port_forward') : null,
            'metrics' => is_array(data_get($payload, 'metrics')) ? data_get($payload, 'metrics') : null,
            'agent' => is_array(data_get($payload, 'agent')) ? data_get($payload, 'agent') : null,
            'node_failures' => $this->normalizeNodeFailures(data_get($payload, 'node_failures')),
            'tls_certificates' => $this->normalizeTlsCertificates(data_get($payload, 'tls_certificates')),
            'updated_at' => now()->timestamp,
        ];

        if (!$machine->is_active) {
            $status['machine'] = ['is_active' => false];
            $machine->forceFill([
                'last_seen_at' => now()->timestamp,
                'load_status' => $status,
            ])->save();

            return response()->json([
                'data' => true,
                'reload' => false,
                'upgrade' => null,
            ]);
        }

        $upgradeState = $this->resolveUpgradeState($machine, $status, data_get($payload, 'upgrade'));
        app(ServerTlsCertificateService::class)->handleMachineStatus($machine, $status);
        $reload = app(ZeroSslCertificateService::class)->handleMachineStatus(
            $machine,
            $status,
            $this->resolveSubscriptionProxySiteId($this->resolvePanelBaseURL($request))
        );
        if (!$reload && $this->shouldRequestReloadForMachineConfigDrift($machine, $status)) {
            $reload = true;
        }
        if (!$reload && $this->shouldRequestReloadForSubscriptionProxyDrift($request, $machine, $status)) {
            $reload = true;
        }

        $machine->forceFill([
            'last_seen_at' => now()->timestamp,
            'load_status' => $status,
            'upgrade_state' => $upgradeState,
        ])->save();
        $upgradeCommand = $this->buildUpgradeCommand($machine, $this->resolvePanelBaseURL($request));

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

        return response()->json([
            'data' => true,
            'reload' => $reload,
            'upgrade' => $upgradeCommand,
        ]);
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

    private function normalizeNodeFailures(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $out = [];
        foreach (array_values($value) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $error = $this->statusString(data_get($item, 'error'), 1000);
            if ($error === '') {
                continue;
            }
            $out[] = [
                'api_host' => $this->statusString(data_get($item, 'api_host'), 255),
                'node_id' => $this->statusInt(data_get($item, 'node_id')),
                'machine_id' => $this->statusInt(data_get($item, 'machine_id')),
                'node_type' => $this->statusString(data_get($item, 'node_type'), 64),
                'error' => $error,
            ];
            if (count($out) >= 50) {
                break;
            }
        }

        return $out;
    }

    private function normalizeTlsCertificates(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $out = [];
        foreach (array_values($value) as $item) {
            if (!is_array($item)) {
                continue;
            }

            $out[] = [
                'node_id' => $this->statusInt(data_get($item, 'node_id')),
                'machine_id' => $this->statusInt(data_get($item, 'machine_id')),
                'tag' => $this->statusString(data_get($item, 'tag'), 128),
                'protocol' => $this->statusString(data_get($item, 'protocol'), 32),
                'sni' => $this->statusString(data_get($item, 'sni'), 255),
                'status' => $this->statusString(data_get($item, 'status'), 32),
                'sha256_hex' => $this->statusString(data_get($item, 'sha256_hex'), 128),
            ];

            if (count($out) >= 200) {
                break;
            }
        }

        return $out;
    }

    private function resolveUpgradeState(ServerMachine $machine, array $status, mixed $reported): ?array
    {
        $state = is_array($machine->upgrade_state) ? $machine->upgrade_state : null;
        if (empty($state)) {
            return null;
        }

        $now = now()->timestamp;
        $targetVersion = trim((string) ($state['target_version'] ?? ''));
        $component = $this->normalizeUpgradeComponent($state['component'] ?? 'node');
        $state['component'] = $component;
        $currentVersion = $this->currentVersionForUpgradeComponent($status, $component);
        $reportedStatus = '';

        if (is_array($reported) && (string) ($reported['id'] ?? '') === (string) ($state['id'] ?? '')) {
            $reportedStatus = trim((string) ($reported['status'] ?? ''));
            $reportedComponent = $this->normalizeUpgradeComponent($reported['component'] ?? $component);
            if ($reportedComponent !== $component) {
                $state['component'] = $reportedComponent;
                $component = $reportedComponent;
            }
            $this->mergeUpgradeReportFields($state, $reported);

            if ($reportedStatus === 'running') {
                $state['status'] = 'running';
                $state['started_at'] = $state['started_at'] ?? $now;
                $state['updated_at'] = $now;
            } elseif ($reportedStatus === 'failed') {
                $state['status'] = 'failed';
                $state['finished_at'] = $state['finished_at'] ?? $now;
                $state['updated_at'] = $now;
                $state['error'] = $this->statusString(
                    data_get($reported, 'error'),
                    1000
                ) ?: ($state['phase'] ?? 'upgrade_failed');
            } elseif ($reportedStatus === 'succeeded') {
                if ($this->upgradeIdentityMatches($state, $status)) {
                    $state['status'] = 'succeeded';
                    $state['finished_at'] = $state['finished_at'] ?? $now;
                    $state['updated_at'] = $now;
                    $state['current_version'] = $currentVersion !== '' ? $currentVersion : $targetVersion;
                    $runtimeHash = $this->statusHash(data_get($status, 'runtime.binary_sha256'));
                    if ($runtimeHash !== null) {
                        $state['running_binary_sha256'] = $runtimeHash;
                    }
                    unset($state['error']);
                } else {
                    $state['status'] = 'failed';
                    $state['finished_at'] = $state['finished_at'] ?? $now;
                    $state['updated_at'] = $now;
                    $state['error'] = $this->upgradeIdentityMismatchError($state, $status);
                }
            }
        }

        if (
            $reportedStatus === ''
            && !in_array((string) ($state['status'] ?? ''), ['failed', 'succeeded'], true)
            && $targetVersion !== ''
            && $currentVersion !== ''
            && $this->versionsMatch($currentVersion, $targetVersion)
            && $this->upgradeIdentityMatches($state, $status)
        ) {
            $state['status'] = 'succeeded';
            $state['current_version'] = $currentVersion;
            $state['finished_at'] = $state['finished_at'] ?? $now;
            $state['updated_at'] = $now;
            $runtimeHash = $this->statusHash(data_get($status, 'runtime.binary_sha256'));
            if ($runtimeHash !== null) {
                $state['running_binary_sha256'] = $runtimeHash;
            }
            unset($state['error']);
        }

        $statusValue = (string) ($state['status'] ?? '');
        $requestedAt = (int) ($state['requested_at'] ?? 0);
        if (in_array($statusValue, ['queued', 'dispatched', 'running'], true) && $requestedAt > 0 && $requestedAt < $now - 1200) {
            $state['status'] = 'failed';
            $state['finished_at'] = $now;
            $state['updated_at'] = $now;
            $expectedHash = $this->statusHash($state['expected_binary_sha256'] ?? null);
            $runtimeHash = $this->statusHash(data_get($status, 'runtime.binary_sha256'));
            $state['error'] = $expectedHash === null || $runtimeHash === null
                ? 'upgrade_identity_unavailable'
                : 'upgrade_timeout';
        }

        return $state;
    }

    private function mergeUpgradeReportFields(array &$state, array $reported): void
    {
        foreach (['phase', 'current_version', 'previous_version', 'rollback_error', 'error'] as $field) {
            if (!array_key_exists($field, $reported)) {
                continue;
            }
            $value = $this->statusString($reported[$field], 1000);
            if ($value !== '') {
                $state[$field] = $value;
            }
        }

        foreach ([
            'expected_archive_sha256',
            'expected_binary_sha256',
            'previous_binary_sha256',
            'installed_binary_sha256',
            'running_binary_sha256',
        ] as $field) {
            if (!array_key_exists($field, $reported)) {
                continue;
            }
            $value = $this->statusHash($reported[$field]);
            if ($value !== null) {
                $state[$field] = $value;
            }
        }

        foreach (['previous_pid', 'running_pid', 'started_at', 'finished_at', 'updated_at'] as $field) {
            if (!array_key_exists($field, $reported)) {
                continue;
            }
            $value = $this->statusInt($reported[$field]);
            if ($value !== null) {
                $state[$field] = $value;
            }
        }

        foreach (['rollback_performed', 'rollback_succeeded'] as $field) {
            if (!array_key_exists($field, $reported)) {
                continue;
            }
            $value = $this->statusBool($reported[$field]);
            if ($value !== null) {
                $state[$field] = $value;
            }
        }
    }

    private function upgradeIdentityMatches(array $state, array $status): bool
    {
        $targetVersion = trim((string) ($state['target_version'] ?? ''));
        $currentVersion = $this->currentVersionForUpgradeComponent(
            $status,
            $this->normalizeUpgradeComponent($state['component'] ?? 'node')
        );
        if (!$this->versionsMatch($currentVersion, $targetVersion)) {
            return false;
        }

        $expectedHash = $this->statusHash($state['expected_binary_sha256'] ?? null);
        if ($expectedHash === null) {
            return false;
        }

        $runtimeHash = $this->statusHash(data_get($status, 'runtime.binary_sha256'));
        return $runtimeHash !== null && hash_equals($expectedHash, $runtimeHash);
    }

    private function upgradeIdentityMismatchError(array $state, array $status): string
    {
        $expectedHash = trim((string) ($state['expected_binary_sha256'] ?? ''));
        $runtimeHash = trim((string) data_get($status, 'runtime.binary_sha256', ''));
        if ($expectedHash === '') {
            return 'upgrade_identity_unavailable';
        }
        if (strcasecmp($expectedHash, $runtimeHash) !== 0) {
            return 'runtime_binary_sha256_mismatch';
        }

        return 'runtime_version_mismatch';
    }
    private function buildUpgradeCommand(ServerMachine $machine, string $panelBaseUrl): ?array
    {
        $state = is_array($machine->upgrade_state) ? $machine->upgrade_state : null;
        if (($state['status'] ?? '') !== 'queued') {
            return null;
        }

        $targetVersion = trim((string) ($state['target_version'] ?? ''));
        if (!$this->isValidKelinodeVersion($targetVersion)) {
            $state['status'] = 'failed';
            $state['error'] = 'invalid_target_version';
            $state['finished_at'] = now()->timestamp;
            $state['updated_at'] = now()->timestamp;
            $machine->forceFill(['upgrade_state' => $state])->save();
            return null;
        }

        $component = $this->normalizeUpgradeComponent($state['component'] ?? 'node');
        $distribution = app(MachineReleaseDistributionService::class);
        $source = $distribution->source();
        $release = $distribution->findLocalRelease(
            $distribution->componentFromUpgradeComponent($component),
            $targetVersion,
            MachineReleaseDistributionService::PLATFORM_LINUX_X86_64
        );
        if ($release) {
            $state['expected_archive_sha256'] = strtolower((string) $release->sha256);
            $state['expected_binary_sha256'] = strtolower((string) $release->binary_sha256);
        }

        $state['status'] = 'dispatched';
        $state['dispatched_at'] = now()->timestamp;
        $state['updated_at'] = now()->timestamp;
        $machine->forceFill(['upgrade_state' => $state])->save();

        $command = [
            'id' => (string) ($state['id'] ?? ''),
            'component' => $component,
            'target_version' => $targetVersion,
        ];
        if ($release) {
            $command += [
                'expected_archive_sha256' => strtolower((string) $release->sha256),
                'expected_binary_sha256' => strtolower((string) $release->binary_sha256),
            ];
        }

        $releaseBaseUrl = $distribution->releaseBaseUrl($panelBaseUrl);
        if ($source !== MachineReleaseDistributionService::SOURCE_GITHUB && $releaseBaseUrl !== '') {
            $command += [
                'release_source' => $source,
                'release_base_url' => $releaseBaseUrl,
                'release_auth' => $distribution->releaseAuth($machine),
            ];
        }

        return $command;
    }
    private function versionsMatch(string $current, string $target): bool
    {
        $current = ltrim(trim($current), 'vV');
        $target = ltrim(trim($target), 'vV');
        if ($current === '' || $target === '') {
            return false;
        }
        if ($current === $target) {
            return true;
        }
        if (
            preg_match('/^\d+(?:\.\d+)+(?:[-+][0-9A-Za-z._-]+)?$/', $current)
            && preg_match('/^\d+(?:\.\d+)+(?:[-+][0-9A-Za-z._-]+)?$/', $target)
        ) {
            return version_compare($current, $target, '>=');
        }

        return false;
    }

    private function isValidKelinodeVersion(string $version): bool
    {
        return (bool) preg_match('/^v?[0-9A-Za-z][0-9A-Za-z._-]{0,63}$/', trim($version));
    }

    private function normalizeUpgradeComponent(mixed $value): string
    {
        $value = strtolower(trim((string) ($value ?? '')));
        if (in_array($value, ['core', 'keli-core', 'keli-core-rs'], true)) {
            return 'core';
        }
        if (in_array($value, ['kelinode-rs', 'native-node', 'native_node'], true)) {
            return 'kelinode-rs';
        }

        return 'node';
    }

    private function currentVersionForUpgradeComponent(array $status, string $component): string
    {
        if ($component === 'core') {
            return trim((string) (
                data_get($status, 'core.version')
                ?: data_get($status, 'core.versions.keli-core-rs')
                ?: data_get($status, 'core.versions.core')
                ?: data_get($status, 'core.keli_core_rs_version')
                ?: ''
            ));
        }

        return trim((string) ($status['version'] ?? ''));
    }

    private function statusInt(mixed $value): ?int
    {
        if (!is_scalar($value) || !is_numeric($value)) {
            return null;
        }
        $int = (int) $value;
        return $int > 0 ? $int : null;
    }

    private function statusBool(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (!is_scalar($value)) {
            return null;
        }

        return match (strtolower(trim((string) $value))) {
            '1', 'true', 'yes' => true,
            '0', 'false', 'no' => false,
            default => null,
        };
    }

    private function statusHash(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }
        $value = strtolower(trim((string) $value));
        return preg_match('/^[a-f0-9]{64}$/', $value) === 1 ? $value : null;
    }
    private function statusString(mixed $value, int $limit): string
    {
        if (!is_scalar($value)) {
            return '';
        }
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }

        return substr($value, 0, max(1, $limit));
    }

    private function buildBaseConfig(): array
    {
        $settings = app(NodeRealtimeSettings::class);
        $wsURL = $settings->resolvedPublicUrl();
        $realtimeEnabled = $settings->enabled() && $wsURL !== '';

        return [
            'push_interval' => (int) admin_setting('server_push_interval', 60),
            'pull_interval' => (int) admin_setting('server_pull_interval', 60),
            'realtime' => [
                'enabled' => $realtimeEnabled,
                'url' => $realtimeEnabled ? $wsURL : '',
                'ping_interval' => (int) admin_setting('server_realtime_ping_interval', 30),
            ],
        ];
    }

    private function shouldRequestReloadForMachineConfigDrift(ServerMachine $machine, array $status): bool
    {
        $boundNodeIds = ServerService::getMachineNodes($machine)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->sort()
            ->values()
            ->all();

        $runtimeNodeIds = collect(data_get($status, 'runtime.node_statuses', []))
            ->filter(fn ($row): bool => is_array($row) && is_numeric($row['node_id'] ?? null))
            ->map(fn ($row): int => (int) $row['node_id'])
            ->unique()
            ->sort()
            ->values()
            ->all();
        if ($runtimeNodeIds !== []) {
            return $runtimeNodeIds !== $boundNodeIds;
        }

        $runtimeNodes = data_get($status, 'runtime.nodes');
        if (!is_numeric($runtimeNodes)) {
            return false;
        }

        return (int) $runtimeNodes !== count($boundNodeIds);
    }

    private function shouldRequestReloadForSubscriptionProxyDrift(Request $request, ServerMachine $machine, array $status): bool
    {
        $desired = $this->buildSubscriptionProxyConfig($request, $machine);
        if (!(bool) ($desired['enabled'] ?? false)) {
            return false;
        }

        $reported = data_get($status, 'agent.subscription_proxy');
        if (!is_array($reported)) {
            return true;
        }

        if ((bool) data_get($reported, 'enabled', false) !== true) {
            return true;
        }

        if ($this->reportedProxyProfileCount($reported, 'profiles') !== count($desired['profiles'] ?? [])) {
            return true;
        }

        if ($this->reportedProxyProfileCount($reported, 'website_profiles') !== count($desired['website_profiles'] ?? [])) {
            return true;
        }

        $desiredDomain = trim((string) ($desired['certificate_domain'] ?? ''));
        $reportedDomain = trim((string) data_get($reported, 'certificate_domain', ''));
        if ($desiredDomain !== '' && $reportedDomain !== '' && $reportedDomain !== $desiredDomain) {
            return true;
        }

        if ($this->subscriptionProxyDesiredConfigChanged($machine, $desired)) {
            return true;
        }

        $state = is_array($machine->subproxy_cert_state) ? $machine->subproxy_cert_state : [];
        $certificateId = trim((string) ($state['certificate_id'] ?? ''));
        $certificateStatus = trim((string) ($state['status'] ?? ''));
        if ($certificateId === '' || $certificateStatus === '' || $certificateStatus === 'delegated') {
            return false;
        }

        $reportedCertificateId = trim((string) data_get($reported, 'certificate_id', ''));
        if (in_array($certificateStatus, ['draft', 'pending_validation', 'waiting_agent_reload'], true)) {
            if (empty($state['validation_path']) || empty($state['validation_content'])) {
                return false;
            }

            return $reportedCertificateId !== $certificateId
                || (bool) data_get($reported, 'validation_ready', false) !== true;
        }

        if ($certificateStatus === 'issued' && $this->hasUsableZeroSslCertificateChain($state)) {
            return $reportedCertificateId !== $certificateId
                || trim((string) data_get($reported, 'cert_not_after', '')) === ''
                || (bool) data_get($reported, 'need_certificate', false) === true;
        }

        return false;
    }

    private function subscriptionProxyDesiredConfigChanged(ServerMachine $machine, array $desired): bool
    {
        $signature = $this->subscriptionProxyDesiredConfigSignature($desired);
        if ($signature === '') {
            return false;
        }

        $state = is_array($machine->subproxy_cert_state) ? $machine->subproxy_cert_state : [];
        $current = trim((string) ($state['config_signature'] ?? ''));
        if ($current !== '' && hash_equals($current, $signature)) {
            return false;
        }

        $state['config_signature'] = $signature;
        $state['config_signature_at'] = now()->timestamp;
        $machine->setAttribute('subproxy_cert_state', $state);

        return true;
    }

    private function subscriptionProxyDesiredConfigSignature(array $desired): string
    {
        if (!(bool) ($desired['enabled'] ?? false)) {
            return '';
        }

        $payload = [
            'enabled' => true,
            'https_listen' => trim((string) ($desired['https_listen'] ?? '')),
            'http_listen' => trim((string) ($desired['http_listen'] ?? '')),
            'certificate_domain' => trim((string) ($desired['certificate_domain'] ?? '')),
            'challenge_dir' => trim((string) ($desired['challenge_dir'] ?? '')),
            'cert_file' => trim((string) ($desired['cert_file'] ?? '')),
            'key_file' => trim((string) ($desired['key_file'] ?? '')),
            'allow_http_fallback' => (bool) ($desired['allow_http_fallback'] ?? false),
            'max_response_bytes' => max(0, (int) ($desired['max_response_bytes'] ?? 0)),
            'profiles' => $this->subscriptionProxyProfileSignatureRows($desired['profiles'] ?? [], [
                'site_id',
                'upstream_base_url',
                'subscribe_path',
            ]),
            'website_profiles' => $this->subscriptionProxyProfileSignatureRows($desired['website_profiles'] ?? [], [
                'site_id',
                'upstream_base_url',
                'path_prefix',
            ]),
        ];

        return hash('sha256', (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function subscriptionProxyProfileSignatureRows(mixed $profiles, array $keys): array
    {
        if (!is_array($profiles)) {
            return [];
        }

        return collect($profiles)
            ->filter(fn ($profile): bool => is_array($profile))
            ->map(function (array $profile) use ($keys): array {
                $row = [];
                foreach ($keys as $key) {
                    $row[$key] = trim((string) ($profile[$key] ?? ''));
                }

                return $row;
            })
            ->values()
            ->all();
    }

    private function reportedProxyProfileCount(array $reported, string $key): int
    {
        $value = data_get($reported, $key);
        if (is_array($value)) {
            return count($value);
        }
        if (is_numeric($value)) {
            return max(0, (int) $value);
        }
        return 0;
    }

    private function authenticateMachine(Request $request, bool $allowInactive = false): ?ServerMachine
    {
        $machineId = $request->input('machine_id');
        $token = trim((string) $request->input('token', ''));
        if (!is_scalar($machineId) || (int) $machineId <= 0 || $token === '') {
            return null;
        }

        $machine = ServerMachine::query()
            ->whereKey((int) $machineId)
            ->when(!$allowInactive, function ($query): void {
                $query->where('is_active', true);
            })
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
        $subscriptionEnabled = $this->machineWantsSubscriptionProxy($machine);
        $websiteEnabled = $this->machineWantsWebsiteProxy($machine);
        if (!$subscriptionEnabled && !$websiteEnabled) {
            return ['enabled' => false];
        }

        $upstreamBaseURL = $this->resolvePanelBaseURL($request);
        $siteId = $this->resolveSubscriptionProxySiteId($upstreamBaseURL);
        $subscribePath = $this->resolveSubscriptionProxySubscribePath($siteId);

        $config = [
            'enabled' => true,
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
            'cert_file' => (string) admin_setting('subscription_proxy_cert_file', '/etc/v2node/subproxy/fullchain.pem'),
            'key_file' => (string) admin_setting('subscription_proxy_key_file', '/etc/v2node/subproxy/key.pem'),
            'zerossl' => $this->buildZeroSslAgentConfig($machine),
            'allow_http_fallback' => (bool) admin_setting('subscription_proxy_allow_http_fallback', false),
            'max_response_bytes' => max(1024 * 1024, (int) admin_setting('subscription_proxy_max_response_bytes', 10485760)),
            'profiles' => [],
            'website_profiles' => [],
        ];

        if ($subscriptionEnabled) {
            $subscriptionProfile = [
                'site_id' => $siteId,
                'upstream_base_url' => $upstreamBaseURL,
                'subscribe_path' => $subscribePath,
            ];
            $config['site_id'] = $subscriptionProfile['site_id'];
            $config['upstream_base_url'] = $subscriptionProfile['upstream_base_url'];
            $config['subscribe_path'] = $subscriptionProfile['subscribe_path'];
            $config['profiles'][] = $subscriptionProfile;
        }

        if ($websiteEnabled) {
            $config['website_profiles'][] = [
                'site_id' => $siteId,
                'upstream_base_url' => $upstreamBaseURL,
                'path_prefix' => $this->resolveWebsiteProxyPathPrefix($machine),
            ];
        }

        return $config;
    }

    private function machineWantsSubscriptionProxy(ServerMachine $machine): bool
    {
        return (bool) admin_setting('subscription_proxy_enable', false)
            && (bool) $machine->getAttribute('subproxy_enabled');
    }

    private function machineWantsWebsiteProxy(ServerMachine $machine): bool
    {
        return (bool) admin_setting('website_proxy_enable', false)
            && (bool) $machine->getAttribute('webproxy_enabled');
    }

    private function resolveWebsiteProxyPathPrefix(ServerMachine $machine): string
    {
        $value = trim((string) ($machine->getAttribute('webproxy_path_prefix') ?: admin_setting('website_proxy_path_prefix', '/')));
        if ($value === '' || $value === '/') {
            return '/';
        }
        return '/' . trim($value, '/');
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

    private function resolveSubscriptionProxySubscribePath(string $siteId): string
    {
        return 'sub/' . trim($siteId, '/');
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
        $state = is_array($machine->subproxy_cert_state) ? $machine->subproxy_cert_state : [];
        $requestIP = trim((string) $request->ip());
        $configured = trim((string) ($machine->subproxy_cert_domain ?? ''));
        if ($configured !== '') {
            if ($this->shouldIgnoreConfiguredCertificateDomain($configured, $state)) {
                return $this->resolveAutomaticCertificateDomain($requestIP, $machine, $state);
            }
            return $configured;
        }

        return $this->resolveAutomaticCertificateDomain($requestIP, $machine, $state);
    }

    private function resolveAutomaticCertificateDomain(string $requestIP, ServerMachine $machine, array $state): string
    {
        if ($this->isIPv4Address($requestIP)) {
            return $requestIP;
        }

        $statusIPv4 = $this->machineStatusIPv4($machine);
        if ($statusIPv4 !== '') {
            return $statusIPv4;
        }

        $domain = trim((string) ($state['domain'] ?? ''));
        if ($this->isIPv4Address($domain)) {
            return $domain;
        }

        return '';
    }

    private function machineStatusIPv4(ServerMachine $machine): string
    {
        $status = is_array($machine->load_status) ? $machine->load_status : [];
        $candidates = [
            data_get($status, 'ip.public_ipv4'),
            data_get($status, 'ip.panel_seen'),
        ];
        $local = data_get($status, 'ip.local');
        if (is_array($local)) {
            $candidates = array_merge($candidates, $local);
        }

        foreach ($candidates as $candidate) {
            if (!is_scalar($candidate)) {
                continue;
            }
            $ip = trim((string) $candidate);
            if ($this->isIPv4Address($ip)) {
                return $ip;
            }
        }

        return '';
    }

    private function isIPv4Address(string $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
    }

    private function shouldIgnoreConfiguredCertificateDomain(string $configured, array $state): bool
    {
        $source = trim((string) ($state['domain_source'] ?? ''));
        if ($source === 'auto') {
            return true;
        }
        if ($source !== '') {
            return false;
        }

        $stateDomain = trim((string) ($state['domain'] ?? ''));
        if ($stateDomain === '' || $stateDomain !== $configured) {
            return false;
        }

        return filter_var($configured, FILTER_VALIDATE_IP) !== false
            && ((string) ($state['provider'] ?? '') === 'zerossl' || trim((string) ($state['certificate_id'] ?? '')) !== '');
    }

    private function buildZeroSslAgentConfig(ServerMachine $machine): array
    {
        $state = is_array($machine->subproxy_cert_state) ? $machine->subproxy_cert_state : [];
        if (empty($state)) {
            return ['status' => 'idle'];
        }
        if (($state['status'] ?? '') === 'delegated') {
            return [
                'status' => 'delegated',
                'domain' => (string) ($state['domain'] ?? ''),
                'domain_source' => (string) ($state['domain_source'] ?? ''),
                'certificate_owner_site_id' => (string) ($state['certificate_owner_site_id'] ?? ''),
                'last_error' => $state['last_error'] ?? null,
                'updated_at' => (string) ($state['updated_at'] ?? ''),
            ];
        }

        $config = [
            'status' => (string) ($state['status'] ?? 'idle'),
            'certificate_id' => (string) ($state['certificate_id'] ?? ''),
            'domain' => (string) ($state['domain'] ?? ''),
            'domain_source' => (string) ($state['domain_source'] ?? ''),
            'validation_path' => (string) ($state['validation_path'] ?? ''),
            'validation_content' => $state['validation_content'] ?? null,
            'validation_requested_at' => (string) ($state['validation_requested_at'] ?? ''),
            'expires_at' => (string) ($state['expires_at'] ?? ''),
            'last_error' => $state['last_error'] ?? null,
            'updated_at' => (string) ($state['updated_at'] ?? ''),
        ];

        if (($state['status'] ?? '') === 'issued' && $this->hasUsableZeroSslCertificateChain($state)) {
            $config['certificate_pem'] = (string) ($state['certificate_pem'] ?? '');
            $config['ca_bundle_pem'] = (string) ($state['ca_bundle_pem'] ?? '');
        }

        return $config;
    }

    private function hasUsableZeroSslCertificateChain(array $state): bool
    {
        $certificate = trim((string) ($state['certificate_pem'] ?? ''));
        if ($certificate === '') {
            return false;
        }

        if (trim((string) ($state['ca_bundle_pem'] ?? '')) !== '') {
            return true;
        }

        return preg_match_all('/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s', $certificate) > 1;
    }
}
