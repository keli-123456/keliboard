<?php

namespace App\Support;

class ProtocolCapabilityService
{
    public const DEFAULT_CLIENT_SCOPE = 'subscription';

    public function __construct(
        protected array $config = []
    ) {
    }

    public function getClientDefinitions(): array
    {
        return $this->config['clients'] ?? [];
    }

    public function getRuntimeDefinitions(): array
    {
        return $this->config['runtimes'] ?? [];
    }

    public function resolveClientFamily(?string $clientName): ?string
    {
        if (!$clientName) {
            return null;
        }

        foreach ($this->getClientDefinitions() as $family => $clientConfig) {
            foreach (($clientConfig['aliases'] ?? []) as $alias) {
                if (strcasecmp((string) $alias, $clientName) === 0) {
                    return $family;
                }
            }
        }

        return null;
    }

    public function supportsRuntime(string $runtime, array $server): SupportResult
    {
        $facts = $this->extractFacts($server);
        $runtimeConfig = data_get($this->config, "runtimes.{$runtime}.protocols.{$facts['protocol']}");

        if ($runtimeConfig === null) {
            return SupportResult::drop("runtime {$runtime} does not support protocol {$facts['protocol']}");
        }

        if (isset($runtimeConfig['networks']) && $facts['network']) {
            if (!in_array($facts['network'], $runtimeConfig['networks'], true)) {
                return SupportResult::drop("runtime {$runtime} does not support network {$facts['network']}");
            }
        }

        if (isset($runtimeConfig['tls_modes']) && $facts['tls_mode'] !== null) {
            if (!in_array($facts['tls_mode'], $runtimeConfig['tls_modes'], true)) {
                return SupportResult::drop("runtime {$runtime} does not support tls_mode {$facts['tls_mode']}");
            }
        }

        if (isset($runtimeConfig['versions']) && $facts['version'] !== null) {
            if (!in_array($facts['version'], $runtimeConfig['versions'], true)) {
                return SupportResult::drop("runtime {$runtime} does not support version {$facts['version']}");
            }
        }

        return SupportResult::allow();
    }

    public function supportsClient(?string $clientName, ?string $clientVersion, array $server): SupportResult
    {
        $facts = $this->extractFacts($server);
        $normalizedClientName = is_string($clientName) ? strtolower(trim($clientName)) : null;
        $family = $this->resolveClientFamily($normalizedClientName);

        if (!$family) {
            return $this->supportsUnknownClient($facts);
        }

        $clientConfig = data_get($this->config, "clients.{$family}", []);
        $variantConfig = is_string($normalizedClientName)
            ? (data_get($clientConfig, "variants.{$normalizedClientName}", []) ?: [])
            : [];

        $allowedProtocols = $variantConfig['protocols'] ?? $clientConfig['protocols'] ?? null;
        if (is_array($allowedProtocols) && !in_array($facts['protocol'], $allowedProtocols, true)) {
            return SupportResult::drop("client {$family} does not export protocol {$facts['protocol']}");
        }

        $rules = array_merge(
            data_get($clientConfig, "supports.{$facts['protocol']}", []),
            data_get($variantConfig, "supports.{$facts['protocol']}", [])
        );

        if (!$rules) {
            return SupportResult::allow();
        }

        $matchedYes = false;

        foreach ($rules as $rule) {
            if (!$this->matchRule($facts, $rule['when'] ?? [])) {
                continue;
            }

            $support = $rule['support'] ?? 'unknown';
            $minVersion = $rule['min_version'] ?? null;
            if ($minVersion !== null) {
                $compare = $this->compareVersion(
                    (string) ($clientConfig['version_kind'] ?? 'semver'),
                    $clientVersion,
                    $minVersion
                );
                if ($compare < 0) {
                    return SupportResult::drop("client {$family} version too low", $rule);
                }
            }

            if ($support === 'no') {
                return SupportResult::drop((string) ($rule['reason'] ?? "client {$family} unsupported"), $rule);
            }

            if ($support === 'unknown') {
                return SupportResult::drop((string) ($rule['reason'] ?? "client {$family} capability unknown"), $rule);
            }

            if ($support === 'yes') {
                $matchedYes = true;
            }
        }

        return $matchedYes
            ? SupportResult::allow()
            : SupportResult::drop("client {$family} has no compatible rule for protocol {$facts['protocol']}");
    }

    public function assessClientSupport(?string $clientName, array $server): array
    {
        $facts = $this->extractFacts($server);
        $normalizedClientName = is_string($clientName) ? strtolower(trim($clientName)) : null;
        $family = $this->resolveClientFamily($normalizedClientName);

        if (!$family) {
            $result = $this->supportsUnknownClient($facts);
            return [
                'family' => null,
                'status' => $result->supported ? 'allow' : 'block',
                'reason' => $result->reason,
                'matched_rule' => $result->matchedRule,
            ];
        }

        $clientConfig = data_get($this->config, "clients.{$family}", []);
        $variantConfig = is_string($normalizedClientName)
            ? (data_get($clientConfig, "variants.{$normalizedClientName}", []) ?: [])
            : [];

        $allowedProtocols = $variantConfig['protocols'] ?? $clientConfig['protocols'] ?? null;
        if (is_array($allowedProtocols) && !in_array($facts['protocol'], $allowedProtocols, true)) {
            return [
                'family' => $family,
                'status' => 'block',
                'reason' => "client {$family} does not export protocol {$facts['protocol']}",
                'matched_rule' => null,
            ];
        }

        $rules = array_merge(
            data_get($clientConfig, "supports.{$facts['protocol']}", []),
            data_get($variantConfig, "supports.{$facts['protocol']}", [])
        );

        if (!$rules) {
            return [
                'family' => $family,
                'status' => 'allow',
                'reason' => null,
                'matched_rule' => null,
            ];
        }

        $matchedAllow = false;
        $matchedPartialRule = null;

        foreach ($rules as $rule) {
            if (!$this->matchRule($facts, $rule['when'] ?? [])) {
                continue;
            }

            $support = $rule['support'] ?? 'unknown';
            if ($support === 'no' || $support === 'unknown') {
                return [
                    'family' => $family,
                    'status' => 'block',
                    'reason' => (string) ($rule['reason'] ?? "client {$family} unsupported"),
                    'matched_rule' => $rule,
                ];
            }

            if ($support === 'yes') {
                if (!empty($rule['min_version'])) {
                    $matchedPartialRule = $rule;
                    continue;
                }

                $matchedAllow = true;
            }
        }

        if ($matchedPartialRule) {
            $minVersion = (string) ($matchedPartialRule['min_version'] ?? '');
            return [
                'family' => $family,
                'status' => 'partial',
                'reason' => $minVersion !== ''
                    ? "requires {$family} >= {$minVersion}"
                    : "client {$family} may require a newer version",
                'matched_rule' => $matchedPartialRule,
            ];
        }

        if ($matchedAllow) {
            return [
                'family' => $family,
                'status' => 'allow',
                'reason' => null,
                'matched_rule' => null,
            ];
        }

        return [
            'family' => $family,
            'status' => 'block',
            'reason' => "client {$family} has no compatible rule for protocol {$facts['protocol']}",
            'matched_rule' => null,
        ];
    }

    public function filterServersForClient(array $servers, ?string $clientName, ?string $clientVersion): array
    {
        return collect($servers)
            ->filter(function ($server) use ($clientName, $clientVersion) {
                return $this->supportsClient($clientName, $clientVersion, (array) $server)->supported;
            })
            ->values()
            ->all();
    }

    public function extractFacts(array $server): array
    {
        $type = (string) ($server['type'] ?? '');
        $settings = (array) ($server['protocol_settings'] ?? []);
        $features = [];

        if (!empty($server['ports'])) {
            $features[] = 'ports';
        }
        if (!empty($settings['hop_interval'])) {
            $features[] = 'hop_interval';
        }
        if (data_get($settings, 'obfs.open')) {
            $features[] = 'obfs';
        }
        if (data_get($settings, 'bandwidth.up') || data_get($settings, 'bandwidth.down')) {
            $features[] = 'bandwidth';
        }
        if (!empty($settings['zero_rtt_handshake'])) {
            $features[] = 'zero_rtt_handshake';
        }
        if (!empty($settings['padding_scheme'])) {
            $features[] = 'padding_scheme';
        }
        if (is_string(data_get($settings, 'encryption')) && trim((string) data_get($settings, 'encryption')) !== '') {
            $features[] = 'encryption';
        }
        if (!empty($settings['alpn'])) {
            $features[] = 'alpn';
        }
        if (is_string(data_get($settings, 'client_fingerprint')) && trim((string) data_get($settings, 'client_fingerprint')) !== '') {
            $features[] = 'client_fingerprint';
        }
        if (
            data_get($settings, 'idle_session_check_interval') !== null
            || data_get($settings, 'idle_session_timeout') !== null
            || data_get($settings, 'min_idle_session') !== null
        ) {
            $features[] = 'idle_session';
        }
        if (($type === 'anytls' && (int) data_get($settings, 'tls_mode', 1) === 2)
            || ($type === 'vless' && (int) data_get($settings, 'tls', 0) === 2)) {
            $features[] = 'reality';
        }

        return [
            'protocol' => $type,
            'network' => data_get($settings, 'network'),
            'cipher' => data_get($settings, 'cipher'),
            'congestion_control' => data_get($settings, 'congestion_control'),
            'encryption' => data_get($settings, 'encryption'),
            'tls_mode' => match ($type) {
                'vless' => data_get($settings, 'tls'),
                'anytls' => data_get($settings, 'tls_mode', 1),
                default => null,
            },
            'udp_relay_mode' => data_get($settings, 'udp_relay_mode'),
            'version' => match ($type) {
                'hysteria', 'tuic' => (int) data_get($settings, 'version'),
                default => null,
            },
            'flow' => data_get($settings, 'flow'),
            'features' => $features,
        ];
    }

    protected function supportsUnknownClient(array $facts): SupportResult
    {
        return match ($facts['protocol']) {
            'vless' => in_array($facts['network'], [null, 'tcp', 'ws', 'grpc'], true)
                ? SupportResult::allow()
                : SupportResult::drop('unknown client only gets conservative vless transports'),
            'anytls' => SupportResult::drop('unknown client does not receive anytls by default'),
            'hysteria' => (($facts['version'] ?? 2) === 2)
                ? SupportResult::allow()
                : SupportResult::drop('unknown client does not receive legacy hysteria'),
            'tuic' => (($facts['version'] ?? 5) === 5)
                ? SupportResult::allow()
                : SupportResult::drop('unknown client does not receive legacy tuic'),
            default => SupportResult::allow(),
        };
    }

    protected function matchRule(array $facts, array $when): bool
    {
        foreach ($when as $key => $expected) {
            if ($key === 'feature') {
                if (!in_array($expected, $facts['features'], true)) {
                    return false;
                }
                continue;
            }

            $actual = $facts[$key] ?? null;
            if (is_array($expected)) {
                if (!in_array($actual, $expected, true)) {
                    return false;
                }
                continue;
            }

            if ($actual !== $expected) {
                return false;
            }
        }

        return true;
    }

    protected function compareVersion(string $kind, ?string $actual, string $required): int
    {
        if ($actual === null || $actual === '') {
            return -1;
        }

        return match ($kind) {
            'build' => ((int) $actual) <=> ((int) $required),
            default => version_compare($actual, $required),
        };
    }
}
