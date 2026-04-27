<?php

namespace App\Services\SubscriptionProxy;

use App\Models\ServerMachine;
use App\Services\NodeRealtime\NodeRealtimePublisher;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ZeroSslCertificateService
{
    private const API_BASE = 'https://api.zerossl.com';
    private const VALIDATION_METHOD = 'HTTP_CSR_HASH';

    public function handleMachineStatus(ServerMachine $machine, array $status): void
    {
        if (!(bool) admin_setting('subscription_proxy_enable', false) || !(bool) $machine->getAttribute('subproxy_enabled')) {
            return;
        }

        $accessKey = trim((string) admin_setting('zerossl_access_key', ''));
        if ($accessKey === '') {
            return;
        }

        $proxy = data_get($status, 'agent.subscription_proxy');
        if (!is_array($proxy)) {
            return;
        }

        $configuredDomain = trim((string) ($machine->subproxy_cert_domain ?? ''));
        $reportedDomain = trim((string) data_get($proxy, 'certificate_domain', ''));
        $domain = $configuredDomain !== '' ? $configuredDomain : $reportedDomain;
        $csr = trim((string) data_get($proxy, 'csr_pem', ''));
        if ($domain === '' || $csr === '') {
            return;
        }

        try {
            $state = is_array($machine->subproxy_cert_state) ? $machine->subproxy_cert_state : [];
            $previousState = $state;
            if ($reportedDomain !== '' && $reportedDomain !== $domain) {
                $state['status'] = 'waiting_agent_reload';
                $state['last_error'] = sprintf('Agent certificate domain %s does not match configured domain %s; waiting for agent reload.', $reportedDomain, $domain);
                $machine->forceFill([
                    'subproxy_cert_domain' => $domain,
                    'subproxy_cert_state' => $this->withUpdatedAt($state),
                ])->save();
                $this->notifyAgentConfigChanged($machine, $state);
                return;
            }

            $csrHash = hash('sha256', $csr);
            $renewDays = max(1, min(60, (int) admin_setting('subscription_proxy_renew_days', 20)));

            if ($this->shouldCreateCertificate($state, $domain, $csrHash, $renewDays)) {
                $state = $this->createCertificate($accessKey, $domain, $csr, $csrHash, $this->replacementCertificateId($state, $renewDays));
            }

            $validationReady = (bool) data_get($proxy, 'validation_ready', false);
            if ($validationReady && !empty($state['certificate_id']) && in_array(($state['status'] ?? ''), ['draft', 'pending_validation'], true)) {
                $state = $this->maybeRequestValidation($accessKey, $state);
            }

            if (!empty($state['certificate_id']) && ($state['status'] ?? '') !== 'issued') {
                $state = $this->refreshCertificate($accessKey, $state);
            }

            if (($state['status'] ?? '') === 'issued' && empty($state['certificate_pem'])) {
                $state = $this->downloadCertificate($accessKey, $state);
            }

            $notifyAgent = $this->shouldNotifyAgent($state, $proxy);
            $agentConfigSignature = $this->agentConfigSignature($state);
            $agentConfigSignatureChanged = false;
            if ($notifyAgent && $agentConfigSignature !== '' && ($state['agent_config_signature'] ?? '') !== $agentConfigSignature) {
                $state['agent_config_signature'] = $agentConfigSignature;
                $agentConfigSignatureChanged = true;
            }

            $machine->forceFill([
                'subproxy_cert_domain' => $domain,
                'subproxy_cert_state' => $this->withUpdatedAt($state),
            ])->save();
            if ($this->stableStateSignature($previousState) !== $this->stableStateSignature($state) || $agentConfigSignatureChanged) {
                $this->notifyAgentConfigChanged($machine, $state);
            }
        } catch (\Throwable $e) {
            Log::warning('Subscription proxy ZeroSSL automation failed', [
                'machine_id' => (int) $machine->id,
                'error' => $e->getMessage(),
            ]);
            $state = is_array($machine->subproxy_cert_state) ? $machine->subproxy_cert_state : [];
            $state['last_error'] = $e->getMessage();
            $machine->forceFill([
                'subproxy_cert_domain' => $domain,
                'subproxy_cert_state' => $this->withUpdatedAt($state),
            ])->save();
        }
    }

    private function shouldCreateCertificate(array $state, string $domain, string $csrHash, int $renewDays): bool
    {
        if (empty($state['certificate_id'])) {
            return true;
        }
        if (($state['domain'] ?? '') !== $domain) {
            return true;
        }
        if (($state['csr_hash'] ?? '') !== $csrHash) {
            return true;
        }
        return $this->shouldRenew($state, $renewDays);
    }

    private function shouldRenew(array $state, int $renewDays): bool
    {
        if (!in_array(($state['status'] ?? ''), ['issued', 'expiring_soon'], true)) {
            return false;
        }

        $expiresAt = strtotime((string) ($state['expires_at'] ?? '')) ?: 0;
        return $expiresAt > 0 && $expiresAt <= time() + ($renewDays * 86400);
    }

    private function replacementCertificateId(array $state, int $renewDays): ?string
    {
        if (!$this->shouldRenew($state, $renewDays)) {
            return null;
        }

        $id = trim((string) ($state['certificate_id'] ?? ''));
        return $id === '' ? null : $id;
    }

    private function createCertificate(string $accessKey, string $domain, string $csr, string $csrHash, ?string $replacementId = null): array
    {
        $payload = [
            'certificate_domains' => $domain,
            'certificate_csr' => $csr,
            'certificate_validity_days' => 90,
            'strict_domains' => 1,
        ];
        if ($replacementId !== null) {
            $payload['replacement_for_certificate'] = $replacementId;
        }

        $response = Http::asForm()
            ->timeout(30)
            ->post($this->apiURL('/certificates', $accessKey), $payload);
        $payload = $this->jsonResponse($response, 'create certificate');
        $validation = $this->extractHTTPValidation($payload, $domain);

        return [
            'provider' => 'zerossl',
            'certificate_id' => (string) ($payload['id'] ?? ''),
            'domain' => $domain,
            'csr_hash' => $csrHash,
            'status' => (string) ($payload['status'] ?? 'draft'),
            'expires_at' => (string) ($payload['expires'] ?? ''),
            'validation_url_http' => $validation['url'] ?? '',
            'validation_path' => $validation['path'] ?? '',
            'validation_content' => $validation['content'] ?? [],
            'created_at' => now()->toIso8601String(),
            'last_error' => null,
        ];
    }

    private function maybeRequestValidation(string $accessKey, array $state): array
    {
        $lastAttempt = strtotime((string) ($state['validation_requested_at'] ?? '')) ?: 0;
        if ($lastAttempt > 0 && $lastAttempt > time() - 60) {
            return $state;
        }

        $id = (string) $state['certificate_id'];
        $response = Http::asForm()
            ->timeout(30)
            ->post($this->apiURL("/certificates/{$id}/challenges", $accessKey), [
                'validation_method' => self::VALIDATION_METHOD,
            ]);
        $payload = $this->jsonResponse($response, 'request certificate validation');
        $state['status'] = (string) ($payload['status'] ?? 'pending_validation');
        $state['validation_requested_at'] = now()->toIso8601String();
        $state['last_error'] = null;
        return $state;
    }

    private function refreshCertificate(string $accessKey, array $state): array
    {
        $id = (string) ($state['certificate_id'] ?? '');
        if ($id === '') {
            return $state;
        }

        $response = Http::timeout(30)->get($this->apiURL("/certificates/{$id}", $accessKey));
        $payload = $this->jsonResponse($response, 'get certificate');
        $state['status'] = (string) ($payload['status'] ?? ($state['status'] ?? ''));
        $state['expires_at'] = (string) ($payload['expires'] ?? ($state['expires_at'] ?? ''));
        return $state;
    }

    private function downloadCertificate(string $accessKey, array $state): array
    {
        $id = (string) ($state['certificate_id'] ?? '');
        if ($id === '') {
            return $state;
        }

        $response = Http::timeout(30)->get($this->apiURL("/certificates/{$id}/download/json", $accessKey));
        $payload = $this->jsonResponse($response, 'download certificate');
        $state['certificate_pem'] = trim((string) ($payload['certificate.crt'] ?? ''));
        $state['ca_bundle_pem'] = trim((string) ($payload['ca_bundle.crt'] ?? ''));
        $state['downloaded_at'] = now()->toIso8601String();
        $state['last_error'] = null;
        return $state;
    }

    private function extractHTTPValidation(array $payload, string $domain): array
    {
        $methods = data_get($payload, 'validation.other_methods', []);
        if (!is_array($methods) || empty($methods)) {
            return [];
        }

        $method = $methods[$domain] ?? reset($methods);
        if (!is_array($method)) {
            return [];
        }

        $url = (string) ($method['file_validation_url_http'] ?? '');
        return [
            'url' => $url,
            'path' => $url !== '' ? (string) parse_url($url, PHP_URL_PATH) : '',
            'content' => array_values((array) ($method['file_validation_content'] ?? [])),
        ];
    }

    private function jsonResponse($response, string $action): array
    {
        $payload = $response->json();
        if (!$response->successful() || !is_array($payload)) {
            $message = is_array($payload) ? json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $response->body();
            throw new \RuntimeException("ZeroSSL {$action} failed: " . trim((string) $message));
        }
        if (($payload['success'] ?? true) === false) {
            throw new \RuntimeException("ZeroSSL {$action} failed: " . json_encode($payload['error'] ?? $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
        return $payload;
    }

    private function apiURL(string $path, string $accessKey): string
    {
        return self::API_BASE . $path . '?' . http_build_query(['access_key' => $accessKey]);
    }

    private function withUpdatedAt(array $state): array
    {
        $state['updated_at'] = now()->toIso8601String();
        return $state;
    }

    private function shouldNotifyAgent(array $state, array $proxy): bool
    {
        if (!empty($state['validation_path']) && !empty($state['validation_content']) && !(bool) data_get($proxy, 'validation_ready', false)) {
            return true;
        }

        if (($state['status'] ?? '') === 'issued' && !empty($state['certificate_pem'])) {
            $certNotAfter = trim((string) data_get($proxy, 'cert_not_after', ''));
            return $certNotAfter === '' || (bool) data_get($proxy, 'need_certificate', false);
        }

        return false;
    }

    private function notifyAgentConfigChanged(ServerMachine $machine, array $state): void
    {
        try {
            app(NodeRealtimePublisher::class)->invalidateConfig('subscription_proxy.cert_state_changed', [
                'machine_id' => (int) $machine->id,
                'certificate_id' => (string) ($state['certificate_id'] ?? ''),
                'certificate_status' => (string) ($state['status'] ?? ''),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Subscription proxy agent reload notification failed', [
                'machine_id' => (int) $machine->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function stableStateSignature(array $state): string
    {
        unset($state['updated_at'], $state['last_agent_notify_at']);
        ksort($state);
        return hash('sha256', json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
    }

    private function agentConfigSignature(array $state): string
    {
        $config = [
            'certificate_id' => (string) ($state['certificate_id'] ?? ''),
            'status' => (string) ($state['status'] ?? ''),
            'validation_path' => (string) ($state['validation_path'] ?? ''),
            'validation_content' => $state['validation_content'] ?? null,
            'certificate_pem_hash' => !empty($state['certificate_pem']) ? hash('sha256', (string) $state['certificate_pem']) : '',
            'ca_bundle_pem_hash' => !empty($state['ca_bundle_pem']) ? hash('sha256', (string) $state['ca_bundle_pem']) : '',
        ];
        return hash('sha256', json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
    }
}
