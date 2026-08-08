<?php

namespace App\Services\SubscriptionProxy;

use App\Models\ServerMachine;
use App\Services\NodeRealtime\NodeRealtimePublisher;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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

    public function __construct(private LetsEncryptAcmeClient $letsEncrypt)
    {
    }

    public function handleMachineStatus(ServerMachine $machine, array $status, string $currentSiteId = ''): bool
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            return $this->handleMachineStatusWithDatabaseLock($machine, $status, $currentSiteId);
        }

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

    private function handleMachineStatusWithDatabaseLock(ServerMachine $machine, array $status, string $currentSiteId): bool
    {
        $lockName = 'keliboard_zerossl_machine_' . $machine->id;
        $result = DB::selectOne('SELECT GET_LOCK(?, 0) AS acquired', [$lockName]);
        if ((int) ($result->acquired ?? 0) !== 1) {
            return false;
        }

        try {
            $machine = $machine->fresh() ?? $machine;
            return $this->handleMachineStatusLocked($machine, $status, $currentSiteId);
        } finally {
            DB::selectOne('SELECT RELEASE_LOCK(?) AS released', [$lockName]);
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
        $agentIdentity = $this->machineAgentIdentity($status);
        $stateAgentIdentity = trim((string) ($state['agent_identity'] ?? ''));
        if ($stateAgentIdentity !== '' && $agentIdentity !== '' && !hash_equals($stateAgentIdentity, $agentIdentity)) {
            Log::warning('Subscription proxy certificate report ignored from duplicate machine credentials', [
                'machine_id' => (int) $machine->id,
                'certificate_agent_identity' => $stateAgentIdentity,
                'reported_agent_identity' => $agentIdentity,
            ]);
            return false;
        }
        if ($stateAgentIdentity === '' && $agentIdentity !== '') {
            $state['agent_identity'] = $agentIdentity;
        }

        $accessKey = trim((string) admin_setting('zerossl_access_key', ''));
        $provider = $this->certificateProvider($state, $accessKey);
        if ($provider === 'zerossl' && $accessKey === '') {
            $this->saveDiagnosticState(
                $machine,
                $state,
                $domain,
                $hasConfiguredDomain,
                'missing_access_key',
                'ZeroSSL access key is not configured.',
                $provider
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
                'Agent has not reported the subscription proxy certificate domain and CSR yet.',
                $provider
            );
            return false;
        }
        if ($provider === 'zerossl' && $this->isIPv6Address($domain)) {
            $this->saveDiagnosticState(
                $machine,
                $state,
                $domain,
                $hasConfiguredDomain,
                'unsupported_certificate_domain',
                'ZeroSSL subscription proxy certificate automation requires an IPv4 address.',
                $provider
            );
            return false;
        }

        try {
            $previousState = $state;
            if ($this->shouldDeferToCertificateOwner($proxy, $currentSiteId)) {
                $ownerSiteId = $this->certificateOwnerSiteId($proxy);
                $state = $this->delegatedCertificateState($state, $provider, $domain, $ownerSiteId);
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
            $renewWindow = $provider === 'letsencrypt'
                ? max(12, min(120, (int) admin_setting('letsencrypt_renew_hours', 48))) * 3600
                : max(1, min(60, (int) admin_setting('subscription_proxy_renew_days', 20))) * 86400;

            if ($this->shouldCreateCertificate($state, $provider, $domain, $csrHash, $renewWindow)) {
                $state = $provider === 'letsencrypt'
                    ? $this->createLetsEncryptCertificate($domain, $csrHash)
                    : $this->createCertificate($accessKey, $domain, $csr, $csrHash, $this->replacementCertificateId($state, $renewWindow));
            }
            if ($agentIdentity !== '') {
                $state['agent_identity'] = $agentIdentity;
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
                $state = $provider === 'letsencrypt'
                    ? $this->requestLetsEncryptValidation($state)
                    : $this->maybeRequestValidation($accessKey, $state);
            }

            if (!empty($state['certificate_id']) && ($state['status'] ?? '') !== 'issued') {
                $state = $provider === 'letsencrypt'
                    ? $this->refreshLetsEncryptCertificate($state, $csr)
                    : $this->refreshCertificate($accessKey, $state);
            }

            if ($this->shouldDownloadIssuedCertificate($state)) {
                $state = $provider === 'letsencrypt'
                    ? $this->downloadLetsEncryptCertificate($state)
                    : $this->downloadCertificate($accessKey, $state);
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
            Log::warning('Subscription proxy certificate automation failed', [
                'machine_id' => (int) $machine->id,
                'provider' => $provider ?? 'unknown',
                'error' => $e->getMessage(),
            ]);
            if ($provider === 'letsencrypt') {
                $state['status'] = 'error';
                $state['failed_at'] = now()->toIso8601String();
            }
            if (trim((string) ($state['status'] ?? '')) === '') {
                $state['status'] = 'error';
            }
            $state['last_error'] = $e->getMessage();
            $this->saveCertificateState($machine, $state, $domain, $hasConfiguredDomain);
            return false;
        }
    }

    private function machineAgentIdentity(array $status): string
    {
        $hostname = strtolower(trim((string) data_get($status, 'system.hostname', '')));
        if ($hostname !== '' && !in_array($hostname, ['unknown', 'localhost'], true)) {
            return 'hostname:' . $hostname;
        }

        $publicIPv4 = trim((string) data_get($status, 'ip.public_ipv4', ''));
        if (filter_var($publicIPv4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return 'ipv4:' . $publicIPv4;
        }

        foreach (['system.machine_id', 'agent.instance_id'] as $path) {
            $value = strtolower(trim((string) data_get($status, $path, '')));
            if ($value !== '') {
                return $path . ':' . $value;
            }
        }

        return '';
    }
    private function machineWantsSharedHttpsProxy(ServerMachine $machine): bool
    {
        return ((bool) admin_setting('subscription_proxy_enable', false)
                && (bool) $machine->getAttribute('subproxy_enabled'))
            || (bool) $machine->getAttribute('webproxy_enabled');
    }

    private function certificateProvider(array $state, string $zeroSslAccessKey): string
    {
        $configured = strtolower(trim((string) admin_setting('subscription_proxy_certificate_provider', 'zerossl')));
        if (in_array($configured, ['zerossl', 'letsencrypt'], true)) {
            return $configured;
        }

        $current = strtolower(trim((string) ($state['provider'] ?? '')));
        if ($current === 'letsencrypt') {
            return 'letsencrypt';
        }
        if ($current === 'zerossl' && trim((string) ($state['last_error'] ?? '')) !== '') {
            return 'letsencrypt';
        }

        return $zeroSslAccessKey !== '' ? 'zerossl' : 'letsencrypt';
    }

    private function shouldCreateCertificate(array $state, string $provider, string $domain, string $csrHash, int $renewWindow): bool
    {
        if (empty($state['certificate_id'])) {
            return true;
        }
        if (($state['provider'] ?? '') !== $provider) {
            return true;
        }
        if (($state['domain'] ?? '') !== $domain) {
            return true;
        }
        if (($state['csr_hash'] ?? '') !== $csrHash) {
            return true;
        }
        if ($provider === 'letsencrypt' && ($state['status'] ?? '') === 'error') {
            $failedAt = strtotime((string) ($state['failed_at'] ?? ''));
            if ($failedAt === false || $failedAt <= time() - 300) {
                return true;
            }
        }

        return $this->shouldRenew($state, $renewWindow);
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

    private function delegatedCertificateState(array $state, string $provider, string $domain, string $ownerSiteId): array
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

        $state['provider'] = $provider;
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

    private function saveDiagnosticState(ServerMachine $machine, array $state, string $domain, bool $hasConfiguredDomain, string $status, string $message, string $provider): void
    {
        $previousState = $state;
        if ($this->canReplaceWithDiagnosticStatus($state)) {
            $state['provider'] = $provider;
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

    private function shouldRenew(array $state, int $renewWindow): bool
    {
        if (!in_array(($state['status'] ?? ''), ['issued', 'expiring_soon'], true)) {
            return false;
        }

        $expiresAt = strtotime((string) ($state['expires_at'] ?? '')) ?: 0;
        return $expiresAt > 0 && $expiresAt <= time() + $renewWindow;
    }

    private function replacementCertificateId(array $state, int $renewWindow): ?string
    {
        if (!$this->shouldRenew($state, $renewWindow)) {
            return null;
        }

        $id = trim((string) ($state['certificate_id'] ?? ''));
        return $id === '' ? null : $id;
    }

    private function createLetsEncryptCertificate(string $domain, string $csrHash): array
    {
        $order = $this->letsEncrypt->createOrder($domain);
        $authorizations = array_values((array) ($order['authorizations'] ?? []));
        $authorizationURL = trim((string) ($authorizations[0] ?? ''));
        $finalizeURL = trim((string) ($order['finalize'] ?? ''));
        $orderURL = trim((string) ($order['order_url'] ?? ''));
        if ($authorizationURL === '' || $finalizeURL === '' || $orderURL === '') {
            throw new \RuntimeException('Let\'s Encrypt order response is incomplete.');
        }

        $authorization = $this->letsEncrypt->fetch($authorizationURL);
        $challenge = $this->letsEncryptHTTPChallenge($authorization);
        $token = trim((string) ($challenge['token'] ?? ''));
        $challengeURL = trim((string) ($challenge['url'] ?? ''));
        if ($token === '' || $challengeURL === '') {
            throw new \RuntimeException('Let\'s Encrypt order did not provide an HTTP-01 challenge.');
        }

        return [
            'provider' => 'letsencrypt',
            'certificate_id' => $orderURL,
            'domain' => $domain,
            'csr_hash' => $csrHash,
            'status' => 'draft',
            'acme_order_url' => $orderURL,
            'acme_authorization_url' => $authorizationURL,
            'acme_finalize_url' => $finalizeURL,
            'acme_challenge_url' => $challengeURL,
            'validation_path' => '/.well-known/acme-challenge/' . $token,
            'validation_content' => $token . '.' . $this->letsEncrypt->accountThumbprint(),
            'created_at' => now()->toIso8601String(),
            'last_error' => null,
        ];
    }

    private function requestLetsEncryptValidation(array $state): array
    {
        $lastAttempt = strtotime((string) ($state['validation_requested_at'] ?? '')) ?: 0;
        if ($lastAttempt > time() - 60) {
            return $state;
        }
        $url = trim((string) ($state['acme_challenge_url'] ?? ''));
        if ($url === '') {
            throw new \RuntimeException('Let\'s Encrypt challenge URL is missing.');
        }

        $this->letsEncrypt->triggerChallenge($url);
        $state['status'] = 'pending_validation';
        $state['validation_requested_at'] = now()->toIso8601String();
        $state['last_error'] = null;
        return $state;
    }

    private function refreshLetsEncryptCertificate(array $state, string $csr): array
    {
        $authorizationURL = trim((string) ($state['acme_authorization_url'] ?? ''));
        if ($authorizationURL !== '') {
            $authorization = $this->letsEncrypt->fetch($authorizationURL);
            $authorizationStatus = strtolower((string) ($authorization['status'] ?? ''));
            $state['authorization_status'] = $authorizationStatus;
            if ($authorizationStatus === 'invalid') {
                $challenge = $this->letsEncryptHTTPChallenge($authorization);
                $detail = trim((string) data_get($challenge, 'error.detail', 'HTTP-01 validation failed.'));
                throw new \RuntimeException("Let's Encrypt validation failed: {$detail}");
            }
        }

        $orderURL = trim((string) ($state['acme_order_url'] ?? $state['certificate_id'] ?? ''));
        if ($orderURL === '') {
            return $state;
        }
        $order = $this->letsEncrypt->fetch($orderURL);
        $orderStatus = strtolower((string) ($order['status'] ?? ''));
        if ($orderStatus === 'invalid') {
            $detail = trim((string) data_get($order, 'error.detail', 'certificate order failed.'));
            throw new \RuntimeException("Let's Encrypt order failed: {$detail}");
        }

        if ($orderStatus === 'ready') {
            $finalizeURL = trim((string) ($state['acme_finalize_url'] ?? $order['finalize'] ?? ''));
            if ($finalizeURL === '') {
                throw new \RuntimeException('Let\'s Encrypt finalize URL is missing.');
            }
            $order = $this->letsEncrypt->finalize($finalizeURL, $csr);
            $orderStatus = strtolower((string) ($order['status'] ?? 'processing'));
            $state['finalized_at'] = now()->toIso8601String();
        }

        $certificateURL = trim((string) ($order['certificate'] ?? ''));
        if ($orderStatus === 'valid' && $certificateURL !== '') {
            $state['status'] = 'issued';
            $state['acme_certificate_url'] = $certificateURL;
        } elseif ($orderStatus === 'processing') {
            $state['status'] = 'processing';
        } elseif ($orderStatus === 'ready') {
            $state['status'] = 'processing';
        } elseif (($state['status'] ?? '') !== 'draft') {
            $state['status'] = 'pending_validation';
        }
        $state['last_error'] = null;
        return $state;
    }

    private function downloadLetsEncryptCertificate(array $state): array
    {
        $url = trim((string) ($state['acme_certificate_url'] ?? ''));
        if ($url === '') {
            throw new \RuntimeException('Let\'s Encrypt certificate download URL is missing.');
        }
        $chain = $this->letsEncrypt->downloadCertificate($url);
        $blocks = $this->extractPemCertificates($chain);
        if (count($blocks) < 2) {
            throw new \RuntimeException('Let\'s Encrypt certificate chain is incomplete.');
        }

        $state['certificate_pem'] = $blocks[0];
        $state['ca_bundle_pem'] = implode("\n", array_slice($blocks, 1));
        $state['downloaded_at'] = now()->toIso8601String();
        $parsed = openssl_x509_parse($blocks[0]);
        $expiresAt = is_array($parsed) ? (int) ($parsed['validTo_time_t'] ?? 0) : 0;
        if ($expiresAt > 0) {
            $state['expires_at'] = date(DATE_ATOM, $expiresAt);
        }
        $state['last_error'] = null;
        return $state;
    }

    private function letsEncryptHTTPChallenge(array $authorization): array
    {
        foreach ((array) ($authorization['challenges'] ?? []) as $challenge) {
            if (is_array($challenge) && ($challenge['type'] ?? '') === 'http-01') {
                return $challenge;
            }
        }
        return [];
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
