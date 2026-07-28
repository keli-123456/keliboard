<?php

namespace App\Services\SubscriptionProxy;

use App\Models\ServerMachine;
use App\Services\NodeRealtime\NodeRealtimePublisher;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ZeroSslCertificateService
{
    private const API_BASE = 'https://api.zerossl.com';
    private const VALIDATION_METHOD = 'HTTP_CSR_HASH';
    private const SECTIGO_R46_USERTRUST_CROSS_SIGNED_PEM = <<<'PEM'
-----BEGIN CERTIFICATE-----
MIIGlTCCBH2gAwIBAgIRANJ/u8HeNZ5SFq1hSVhgmcQwDQYJKoZIhvcNAQEMBQAw
gYgxCzAJBgNVBAYTAlVTMRMwEQYDVQQIEwpOZXcgSmVyc2V5MRQwEgYDVQQHEwtK
ZXJzZXkgQ2l0eTEeMBwGA1UEChMVVGhlIFVTRVJUUlVTVCBOZXR3b3JrMS4wLAYD
VQQDEyVVU0VSVHJ1c3QgUlNBIENlcnRpZmljYXRpb24gQXV0aG9yaXR5MB4XDTIx
MDMyMjAwMDAwMFoXDTM4MDExODIzNTk1OVowXzELMAkGA1UEBhMCR0IxGDAWBgNV
BAoTD1NlY3RpZ28gTGltaXRlZDE2MDQGA1UEAxMtU2VjdGlnbyBQdWJsaWMgU2Vy
dmVyIEF1dGhlbnRpY2F0aW9uIFJvb3QgUjQ2MIICIjANBgkqhkiG9w0BAQEFAAOC
Ag8AMIICCgKCAgEAk77VNlJ12AEjoBxHQknuY7a3If3EldVIKyZ8FFMQ2nn9K7ct
pNQs+uoy3UnCub0PSD17WphUr55dMXRPB/xQId2kz2hPGxJjbSWZTCqZ80gwYfqB
fB6nCErcPiscHxhMcao1jK34bug7StnllALWiYQTqm3ITzPMUJY3kjPcX4jnn1TZ
SPCYQ9Zm/Z8XOEPFAVEL1+MjDxRdWxTnS77d9MjaAzfR1jmhIVEwg7Bt1zBOlluR
8HAkq79FgWRDDb0hOi886Z4NyyC1QifM2m+b7mQwkDnNk2WBITG1I1AzNyLjOO34
MTDMRf5i+dFdMnlCh99qzFYZQE3Oqrv5tXZJlPEn+JGlg+UGs2MOgNzgElWApjtm
tDmHLcjw0NEU6eQNTQ72XVdyxTscR1ad4tX7gWGMzE2AkDRbt9cUddzYBEifwMEo
iLTpHMqnsfFWt3tJTFnlIBWohAIp+jiUaZpJBo/NH3kUFxIMg3reH7GX7vmXeCik
yESS6X0mBaZYcpt5E9gRX67FOGI0aLKGMI74kGGeMmz1BzbNokxu7Io27fLmmRVE
cMN8vJw5wLTha/eDJSNX2RKA5UnwdQ/vjescm1QotCE8/HwK/+97a3X/ix2gGQWr
+vgrgULoOLq7+6r9PeDzyt9Ol5cp7fMYVumllqy9w5CYsuD5otSmR0N8bc8CAwEA
AaOCASAwggEcMB8GA1UdIwQYMBaAFFN5v1qqK0rPVIDh2JvAnfKyA2bLMB0GA1Ud
DgQWBBRWc1hklfmSGrASKgRieaFAFYghSTAOBgNVHQ8BAf8EBAMCAYYwDwYDVR0T
AQH/BAUwAwEB/zAdBgNVHSUEFjAUBggrBgEFBQcDAQYIKwYBBQUHAwIwEQYDVR0g
BAowCDAGBgRVHSAAMFAGA1UdHwRJMEcwRaBDoEGGP2h0dHA6Ly9jcmwudXNlcnRy
dXN0LmNvbS9VU0VSVHJ1c3RSU0FDZXJ0aWZpY2F0aW9uQXV0aG9yaXR5LmNybDA1
BggrBgEFBQcBAQQpMCcwJQYIKwYBBQUHMAGGGWh0dHA6Ly9vY3NwLnVzZXJ0cnVz
dC5jb20wDQYJKoZIhvcNAQEMBQADggIBADpvBIlq7bMU0cFDT/9P9+BsgCkRgQs0
S6Bf7vJSlWMHwby0VGvxCS0hrbi0K2BINZbEbsVsgpQq04431yyoVn3Hldorgq24
RldRDOOipEZDTFB9wC9HYt1thHF00XeG2C8KC1plwoEzKAIhPvefI/C3cT0CfTXJ
uFjUbKIgSwjNjw6YHtLgoy/hd5+JLUlLco/gzFX/qWbT7tEquOMYpsNKWZj8TLqP
q6zMiG4Na6feEZte6YPXGrMWlTWN341vDedc+yxQqSug79HJUQcOZs7KyDWztmae
QxsPE49UV/8XwrfZtZaYyrs4FpD94Z4Q8dzXGL8+qEJjxgcza7W6PROaClubavd1
VKPm8+aCW77u7SxpR2TFGL6kPdxsKyFijpcunR5V79sUyROfNdzjrAcFWZXK8sbb
9FlnwuVG677JLv+ZVTX5AxLvW5OB4zt5uS+zB62wJ/Wv+jXGAttSAcJec4iFgCWH
Rvdi/jJoSzRLa3nEzx6pFIzclSCnh0u1xCeLcUBypSiPga8W+6PkuoyQq8U9qs9E
oxG5NvrvlyshwUS9yvcZRGw7Ljlx4jJH/BhIPR8kIBCQj1vna9TziZOrw1Of8hDU
bHKFG9Pm8Dp2vbjz/2JH39qvxshPKVllGfq+5klPm7yZRUYTiCMAbqwNdL/nsqF2
Rnnyp58XRStJ
-----END CERTIFICATE-----
PEM;

    public function handleMachineStatus(ServerMachine $machine, array $status, string $currentSiteId = ''): bool
    {
        $lock = Cache::lock('subscription-proxy:zerossl:machine:' . $machine->id, 120);
        if (!$lock->get()) {
            return false;
        }

        try {
            $machine = $machine->fresh() ?? $machine;
            return $this->handleMachineStatusLocked($machine, $status, $currentSiteId);
        } finally {
            $lock->release();
        }
    }

    private function handleMachineStatusLocked(ServerMachine $machine, array $status, string $currentSiteId = ''): bool
    {
        if (!$this->machineWantsSharedHttpsProxy($machine)) {
            return false;
        }

        $proxy = data_get($status, 'agent.subscription_proxy');
        if (!is_array($proxy)) {
            return false;
        }

        $state = is_array($machine->subproxy_cert_state) ? $machine->subproxy_cert_state : [];
        $configuredDomain = trim((string) ($machine->subproxy_cert_domain ?? ''));
        $reportedDomain = trim((string) data_get($proxy, 'certificate_domain', ''));
        $hasConfiguredDomain = $configuredDomain !== '' && !$this->shouldIgnoreConfiguredCertificateDomain($configuredDomain, $state);
        if (!$hasConfiguredDomain) {
            $configuredDomain = '';
        }
        $domain = $configuredDomain !== '' ? $configuredDomain : $reportedDomain;
        $csr = trim((string) data_get($proxy, 'csr_pem', ''));

        $accessKey = trim((string) admin_setting('zerossl_access_key', ''));
        if ($accessKey === '') {
            $this->saveDiagnosticState(
                $machine,
                $state,
                $domain,
                $hasConfiguredDomain,
                'missing_access_key',
                'ZeroSSL access key is not configured.'
            );
            return false;
        }

        if ($domain === '' || $csr === '') {
            $this->saveDiagnosticState(
                $machine,
                $state,
                $domain,
                $hasConfiguredDomain,
                'waiting_agent_csr',
                'Agent has not reported the subscription proxy certificate domain and CSR yet.'
            );
            return false;
        }
        if ($this->isIPv6Address($domain)) {
            $this->saveDiagnosticState(
                $machine,
                $state,
                $domain,
                $hasConfiguredDomain,
                'unsupported_certificate_domain',
                'ZeroSSL subscription proxy certificate automation requires an IPv4 address.'
            );
            return false;
        }

        try {
            $previousState = $state;
            if ($this->shouldDeferToCertificateOwner($proxy, $currentSiteId)) {
                $ownerSiteId = $this->certificateOwnerSiteId($proxy);
                $state = $this->delegatedCertificateState($state, $domain, $ownerSiteId);
                $this->saveCertificateState($machine, $state, $domain, $hasConfiguredDomain);
                $shouldReload = $this->stableStateSignature($previousState) !== $this->stableStateSignature($state);
                if ($shouldReload) {
                    $this->notifyAgentConfigChanged($machine, $state);
                }
                return $shouldReload;
            }

            if ($reportedDomain !== '' && $reportedDomain !== $domain) {
                $state['status'] = 'waiting_agent_reload';
                $state['last_error'] = sprintf('Agent certificate domain %s does not match configured domain %s; waiting for agent reload.', $reportedDomain, $domain);
                $this->saveCertificateState($machine, $state, $domain, $hasConfiguredDomain);
                $this->notifyAgentConfigChanged($machine, $state);
                return true;
            }

            $csrHash = hash('sha256', $csr);
            $renewDays = max(1, min(60, (int) admin_setting('subscription_proxy_renew_days', 20)));

            if ($this->shouldCreateCertificate($state, $domain, $csrHash, $renewDays)) {
                $state = $this->createCertificate($accessKey, $domain, $csr, $csrHash, $this->replacementCertificateId($state, $renewDays));
            }

            $validationReady = (bool) data_get($proxy, 'validation_ready', false);
            if ($validationReady && !empty($state['certificate_id']) && ($state['status'] ?? '') === 'draft') {
                if (!$this->agentHasCurrentValidation($proxy, $state)) {
                    $agentCertificateId = $this->agentCertificateId($proxy);
                    $state['status'] = 'waiting_agent_reload';
                    $state['last_error'] = sprintf(
                        'Agent validation file is for certificate %s, waiting for certificate %s; waiting for agent reload.',
                        $agentCertificateId !== '' ? $agentCertificateId : 'none',
                        (string) $state['certificate_id']
                    );
                    $this->saveCertificateState($machine, $state, $domain, $hasConfiguredDomain);
                    $this->notifyAgentConfigChanged($machine, $state);
                    return true;
                }
                $state = $this->maybeRequestValidation($accessKey, $state);
            }

            if (!empty($state['certificate_id']) && ($state['status'] ?? '') !== 'issued') {
                $state = $this->refreshCertificate($accessKey, $state);
            }

            if ($this->shouldDownloadIssuedCertificate($state)) {
                $state = $this->downloadCertificate($accessKey, $state);
            }

            $notifyAgent = $this->shouldNotifyAgent($state, $proxy);
            $agentConfigSignature = $this->agentConfigSignature($state);
            $agentConfigSignatureChanged = false;
            if ($notifyAgent && $agentConfigSignature !== '' && ($state['agent_config_signature'] ?? '') !== $agentConfigSignature) {
                $state['agent_config_signature'] = $agentConfigSignature;
                $agentConfigSignatureChanged = true;
            }

            $this->saveCertificateState($machine, $state, $domain, $hasConfiguredDomain);
            $shouldReload = $this->stableStateSignature($previousState) !== $this->stableStateSignature($state) || $agentConfigSignatureChanged;
            if ($shouldReload) {
                $this->notifyAgentConfigChanged($machine, $state);
            }
            return $shouldReload;
        } catch (\Throwable $e) {
            Log::warning('Subscription proxy ZeroSSL automation failed', [
                'machine_id' => (int) $machine->id,
                'error' => $e->getMessage(),
            ]);
            if (trim((string) ($state['status'] ?? '')) === '') {
                $state['status'] = 'error';
            }
            $state['last_error'] = $e->getMessage();
            $this->saveCertificateState($machine, $state, $domain, $hasConfiguredDomain);
            return false;
        }
    }

    private function machineWantsSharedHttpsProxy(ServerMachine $machine): bool
    {
        return ((bool) admin_setting('subscription_proxy_enable', false)
                && (bool) $machine->getAttribute('subproxy_enabled'))
            || (bool) $machine->getAttribute('webproxy_enabled');
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

    private function shouldDeferToCertificateOwner(array $proxy, string $currentSiteId = ''): bool
    {
        $ownerSiteId = $this->certificateOwnerSiteId($proxy);
        if ($ownerSiteId === '') {
            return false;
        }

        $siteId = $this->sanitizeSiteId($currentSiteId);
        if ($siteId === '') {
            $siteId = $this->currentSiteId();
        }
        return $siteId !== '' && !hash_equals($siteId, $ownerSiteId);
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

    private function agentHasCurrentValidation(array $proxy, array $state): bool
    {
        $certificateId = trim((string) ($state['certificate_id'] ?? ''));
        if ($certificateId === '') {
            return false;
        }

        $agentCertificateId = $this->agentCertificateId($proxy);
        return $agentCertificateId !== '' && hash_equals($certificateId, $agentCertificateId);
    }

    private function agentCertificateId(array $proxy): string
    {
        return trim((string) data_get($proxy, 'certificate_id', ''));
    }

    private function certificateOwnerSiteId(array $proxy): string
    {
        return $this->sanitizeSiteId((string) data_get($proxy, 'certificate_owner_site_id', ''));
    }

    private function currentSiteId(): string
    {
        $configured = trim((string) admin_setting('subscription_proxy_site_id', ''));
        if ($configured !== '') {
            return $this->sanitizeSiteId($configured);
        }

        $baseURL = rtrim((string) admin_setting('app_url', ''), '/');
        if ($baseURL === '') {
            return '';
        }

        $host = (string) parse_url($baseURL, PHP_URL_HOST);
        $siteId = $this->sanitizeSiteId($host);
        return $siteId !== '' ? $siteId : substr(sha1($baseURL), 0, 12);
    }

    private function sanitizeSiteId(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9._-]+/', '-', $value) ?: '';
        return trim($value, '.-_');
    }

    private function delegatedCertificateState(array $state, string $domain, string $ownerSiteId): array
    {
        foreach ([
            'agent_config_signature',
            'ca_bundle_pem',
            'certificate_id',
            'certificate_pem',
            'created_at',
            'csr_hash',
            'downloaded_at',
            'expires_at',
            'validation_content',
            'validation_path',
            'validation_requested_at',
            'validation_url_http',
        ] as $key) {
            unset($state[$key]);
        }

        $state['provider'] = 'zerossl';
        $state['status'] = 'delegated';
        $state['domain'] = $domain;
        $state['certificate_owner_site_id'] = $ownerSiteId;
        $state['last_error'] = null;
        return $state;
    }

    private function saveCertificateState(ServerMachine $machine, array $state, string $domain, bool $hasConfiguredDomain): void
    {
        $state['domain_source'] = $hasConfiguredDomain ? 'manual' : 'auto';
        $payload = [
            'subproxy_cert_domain' => $hasConfiguredDomain ? $domain : null,
            'subproxy_cert_state' => $this->withUpdatedAt($state),
        ];
        $machine->forceFill($payload)->save();
    }

    private function saveDiagnosticState(ServerMachine $machine, array $state, string $domain, bool $hasConfiguredDomain, string $status, string $message): void
    {
        $previousState = $state;
        if ($this->canReplaceWithDiagnosticStatus($state)) {
            $state['provider'] = 'zerossl';
            $state['status'] = $status;
            if ($domain !== '') {
                $state['domain'] = $domain;
            }
        }
        $state['last_error'] = $message;
        if ($this->stableStateSignature($previousState) === $this->stableStateSignature($state)) {
            return;
        }
        $this->saveCertificateState($machine, $state, $domain, $hasConfiguredDomain);
    }

    private function canReplaceWithDiagnosticStatus(array $state): bool
    {
        $status = trim((string) ($state['status'] ?? ''));
        $certificateId = trim((string) ($state['certificate_id'] ?? ''));
        if ($certificateId !== '') {
            return false;
        }

        return !in_array($status, [
            'delegated',
            'draft',
            'pending_validation',
            'issued',
            'expiring_soon',
            'waiting_agent_reload',
        ], true);
    }

    private function isIPv6Address(string $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
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
        $state['last_error'] = null;
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
        [$certificate, $caBundle] = $this->normalizeDownloadedCertificateChain(
            $this->firstString($payload, ['certificate.crt', 'certificate', 'certificate_pem']),
            $this->firstString($payload, ['ca_bundle.crt', 'ca_bundle', 'ca_bundle.pem', 'ca_bundle_pem'])
        );
        $caBundle = $this->appendLegacyCompatibilityCaBundle($caBundle);
        $state['certificate_pem'] = $certificate;
        $state['ca_bundle_pem'] = $caBundle;
        $state['downloaded_at'] = now()->toIso8601String();
        $state['last_error'] = $this->hasUsableCertificateChain($state)
            ? null
            : 'ZeroSSL issued certificate download did not include a usable CA bundle.';
        return $state;
    }

    private function shouldDownloadIssuedCertificate(array $state): bool
    {
        return ($state['status'] ?? '') === 'issued'
            && !$this->hasUsableCertificateChain($state);
    }

    private function hasUsableCertificateChain(array $state): bool
    {
        $certificate = trim((string) ($state['certificate_pem'] ?? ''));
        if ($certificate === '') {
            return false;
        }

        if (trim((string) ($state['ca_bundle_pem'] ?? '')) !== '') {
            return true;
        }

        return count($this->extractPemCertificates($certificate)) > 1;
    }

    private function normalizeDownloadedCertificateChain(string $certificate, string $caBundle): array
    {
        $certificateBlocks = $this->extractPemCertificates($certificate);
        $caBlocks = $this->extractPemCertificates($caBundle);

        if (empty($certificateBlocks)) {
            return [trim($certificate), trim($caBundle)];
        }

        $leaf = $certificateBlocks[0];
        $chain = array_slice($certificateBlocks, 1);
        foreach ($caBlocks as $block) {
            $chain[] = $block;
        }

        return [$leaf, implode("\n", array_values(array_unique($chain)))];
    }

    private function appendLegacyCompatibilityCaBundle(string $caBundle): string
    {
        $caBundle = trim($caBundle);
        $compatibilityCertificate = trim(self::SECTIGO_R46_USERTRUST_CROSS_SIGNED_PEM);
        if ($caBundle === '' || str_contains($caBundle, $compatibilityCertificate)) {
            return $caBundle;
        }

        return $caBundle . "\n" . $compatibilityCertificate;
    }

    private function extractPemCertificates(string $value): array
    {
        if (!preg_match_all('/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s', $value, $matches)) {
            return [];
        }

        return array_values(array_filter(array_map(static fn ($block) => trim((string) $block), $matches[0])));
    }

    private function firstString(array $payload, array $keys): string
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $payload)) {
                continue;
            }
            $value = trim((string) $payload[$key]);
            if ($value !== '') {
                return $value;
            }
        }
        return '';
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

        if (($state['status'] ?? '') === 'issued' && $this->hasUsableCertificateChain($state)) {
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
