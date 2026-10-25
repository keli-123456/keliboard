<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Http\Controllers\V2\Server\MachineController;
use App\Models\ServerMachine;
use App\Services\SubscriptionProxy\LetsEncryptAcmeClient;
use App\Services\SubscriptionProxy\ZeroSslCertificateService;
use App\Support\Setting;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Psr\Log\NullLogger;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class CertificateFailoverTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    private const CSR = '-----BEGIN CERTIFICATE REQUEST-----test-----END CERTIFICATE REQUEST-----';
    private FailoverAcmeClient $acme;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpInMemoryDatabase();
        app()->instance('db.schema', $this->database->getConnection()->getSchemaBuilder());
        Schema::create('v2_server_machine', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('token');
            $table->boolean('is_active')->default(true);
            $table->boolean('subproxy_enabled')->default(true);
            $table->boolean('webproxy_enabled')->default(false);
            $table->string('subproxy_cert_domain')->nullable();
            $table->json('subproxy_cert_state')->nullable();
            $table->timestamps();
        });
        $this->settings();
        Log::swap(new NullLogger());
        Http::fake(fn () => Http::response(['success' => false, 'error' => ['type' => 'unavailable']], 503));
        Http::preventStrayRequests();
        $this->acme = new FailoverAcmeClient();
        app()->instance(LetsEncryptAcmeClient::class, $this->acme);
    }

    public function test_first_failed_request_records_provider_and_next_report_switches_to_acme(): void
    {
        $machine = $this->machine();
        $this->report($machine);
        $state = $machine->fresh()->subproxy_cert_state;
        $this->assertSame('zerossl', $state['provider']);
        $this->assertTrue($state['provider_failure']);
        $this->assertSame('error', $state['status']);

        $this->report($machine);
        $state = $machine->fresh()->subproxy_cert_state;
        $this->assertSame('letsencrypt', $state['provider']);
        $this->assertSame('draft', $state['status']);
        $this->assertSame(1, $this->acme->orders);
        Http::assertSentCount(1);
    }

    public function test_explicit_zerossl_does_not_fail_over_and_retries_are_throttled(): void
    {
        $this->settings(['subscription_proxy_certificate_provider' => 'zerossl']);
        $machine = $this->machine();
        $this->report($machine);
        $this->report($machine);
        $this->assertSame('zerossl', $machine->fresh()->subproxy_cert_state['provider']);
        $this->assertSame(0, $this->acme->orders);
        Http::assertSentCount(1);
    }

    public function test_unconfigured_provider_defaults_to_auto_and_falls_back_after_zerossl_failure(): void
    {
        $this->settings(['subscription_proxy_certificate_provider' => null]);
        $machine = $this->machine();
        $this->report($machine);
        $this->report($machine);
        $this->assertSame('letsencrypt', $machine->fresh()->subproxy_cert_state['provider']);
        $this->assertSame(1, $this->acme->orders);
        Http::assertSentCount(1);
    }

    public function test_unconfigured_provider_without_a_key_uses_acme_directly(): void
    {
        $this->settings(['subscription_proxy_certificate_provider' => null, 'zerossl_access_key' => '']);
        $machine = $this->machine();
        $this->report($machine);
        $this->assertSame('letsencrypt', $machine->fresh()->subproxy_cert_state['provider']);
        $this->assertSame(1, $this->acme->orders);
        Http::assertNothingSent();
    }

    public function test_missing_key_selects_acme_and_both_provider_failure_has_a_cooldown(): void
    {
        $this->settings(['zerossl_access_key' => '']);
        $this->acme->failCreate = true;
        $machine = $this->machine();
        $this->report($machine);
        $this->report($machine);
        $state = $machine->fresh()->subproxy_cert_state;
        $this->assertSame('letsencrypt', $state['provider']);
        $this->assertSame('error', $state['status']);
        $this->assertSame(1, $this->acme->orders);
        $this->acme->failCreate = false;
        $state['retry_at'] = date(DATE_ATOM, time() - 1);
        $machine->forceFill(['subproxy_cert_state' => $state])->save();
        $this->report($machine);
        $this->assertSame(2, $this->acme->orders);
        $this->assertSame('draft', $machine->fresh()->subproxy_cert_state['status']);
        Http::assertNothingSent();
    }

    public function test_website_only_machine_uses_the_same_failover_path(): void
    {
        $this->settings(['subscription_proxy_enable' => false]);
        $machine = $this->machine([], ['subproxy_enabled' => false, 'webproxy_enabled' => true]);
        $this->report($machine);
        $this->report($machine);
        $this->assertSame('letsencrypt', $machine->fresh()->subproxy_cert_state['provider']);
    }

    public function test_waiting_for_agent_reload_does_not_switch_certificate_authorities(): void
    {
        $state = [
            'provider' => 'zerossl', 'certificate_id' => 'zero-pending',
            'domain' => '203.0.113.10', 'csr_hash' => hash('sha256', self::CSR),
            'status' => 'draft', 'validation_path' => '/.well-known/pki-validation/token.txt',
            'validation_content' => ['validation'],
        ];
        $machine = $this->machine($state);
        $this->report($machine, true, 'stale-agent-id');
        $saved = $machine->fresh()->subproxy_cert_state;
        $this->assertSame('waiting_agent_reload', $saved['status']);
        $this->assertArrayNotHasKey('provider_failure', $saved);
        Http::fake(fn () => Http::response(['status' => 'draft']));
        $this->report($machine);
        $this->assertSame(0, $this->acme->orders);
        $this->assertSame('zerossl', $machine->fresh()->subproxy_cert_state['provider']);
    }

    public function test_renewal_failure_keeps_valid_chain_and_sends_pending_challenge_separately(): void
    {
        $active = $this->active();
        $machine = $this->machine($active);
        $this->report($machine);
        $state = $machine->fresh()->subproxy_cert_state;
        $this->assertSame('issued', $state['status']);
        $this->assertSame('old-issued', $state['certificate_id']);
        $this->assertSame('error', $state['pending_certificate']['status']);
        $this->assertSame($active['probe'], $state['probe']);

        $this->report($machine);
        $state = $machine->fresh()->subproxy_cert_state;
        $this->assertSame('issued', $state['status']);
        $this->assertSame('letsencrypt', $state['pending_certificate']['provider']);
        $this->assertSame($active['certificate_pem'], $state['certificate_pem']);
        $method = new \ReflectionMethod(MachineController::class, 'buildZeroSslAgentConfig');
        $config = $method->invoke(new MachineController(), $machine->fresh());
        $this->assertSame('https://acme.test/order/1', $config['certificate_id']);
        $this->assertSame('/.well-known/acme-challenge/token-1', $config['validation_path']);
        $this->assertSame($active['certificate_pem'], $config['certificate_pem']);
        $this->assertSame($active['ca_bundle_pem'], $config['ca_bundle_pem']);
        $this->assertSame($active['expires_at'], $config['expires_at']);
    }

    public function test_only_complete_replacement_chain_is_promoted_and_download_failure_reuses_order(): void
    {
        $active = $this->active();
        $machine = $this->machine($active);
        $this->report($machine);
        $this->report($machine);
        $this->acme->issued = true;
        $this->acme->failDownload = true;
        $this->report($machine, true, 'https://acme.test/order/1');
        $state = $machine->fresh()->subproxy_cert_state;
        $this->assertSame($active['certificate_pem'], $state['certificate_pem']);
        $this->assertSame('issued', $state['pending_certificate']['status']);
        $this->assertArrayNotHasKey('certificate_pem', $state['pending_certificate']);
        $this->report($machine);
        $this->assertSame(1, $this->acme->downloads);
        $state['pending_certificate']['retry_at'] = date(DATE_ATOM, time() - 1);
        $machine->forceFill(['subproxy_cert_state' => $state])->save();
        $this->acme->failDownload = false;
        $this->report($machine);
        $state = $machine->fresh()->subproxy_cert_state;
        $this->assertSame('issued', $state['status']);
        $this->assertSame('letsencrypt', $state['provider']);
        $this->assertSame(1, $this->acme->orders);
        $this->assertSame(2, $this->acme->downloads);
        $this->assertArrayNotHasKey('pending_certificate', $state);
        $this->assertNotSame($active['certificate_pem'], $state['certificate_pem']);
        $this->assertNotEmpty($state['ca_bundle_pem']);
    }

    public function test_incomplete_zerossl_download_falls_back_without_replacing_the_active_chain(): void
    {
        Http::fake(function ($request) {
            if ($request->method() === 'POST') {
                return Http::response(['id' => 'zero-replacement', 'status' => 'draft']);
            }
            if (str_contains($request->url(), '/download/')) {
                return Http::response(['certificate.crt' => "-----BEGIN CERTIFICATE-----\nleaf\n-----END CERTIFICATE-----"]);
            }
            return Http::response(['id' => 'zero-replacement', 'status' => 'issued']);
        });
        $active = $this->active();
        $machine = $this->machine($active);
        $this->report($machine);
        $saved = $machine->fresh()->subproxy_cert_state;
        $this->assertSame($active['certificate_pem'], $saved['certificate_pem']);
        $this->assertTrue($saved['pending_certificate']['provider_failure']);
        $this->report($machine);
        $saved = $machine->fresh()->subproxy_cert_state;
        $this->assertSame('letsencrypt', $saved['pending_certificate']['provider']);
        $this->assertSame($active['certificate_pem'], $saved['certificate_pem']);
        $this->assertSame(1, $this->acme->orders);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unsafeActiveStates')]
    public function test_expired_different_domain_or_different_key_is_not_retained(array $override): void
    {
        $machine = $this->machine(array_replace($this->active(), $override));
        $this->report($machine);
        $state = $machine->fresh()->subproxy_cert_state;
        $this->assertSame('error', $state['status']);
        $this->assertArrayNotHasKey('pending_certificate', $state);
        $this->assertArrayNotHasKey('certificate_pem', $state);
    }

    public static function unsafeActiveStates(): array
    {
        return [
            [['expires_at' => '2000-01-01T00:00:00+00:00']],
            [['domain' => 'another.example.test']],
            [['csr_hash' => hash('sha256', 'different-key')]],
        ];
    }

    public function test_access_keys_and_query_secrets_are_redacted_before_persisting_or_sending(): void
    {
        Http::fake(fn () => throw new ConnectionException('https://api.zerossl.com/certificates?access_key=test-key&token=private-value'));
        $machine = $this->machine();
        $this->report($machine);
        $error = $machine->fresh()->subproxy_cert_state['last_error'];
        $this->assertStringNotContainsString('test-key', $error);
        $this->assertStringNotContainsString('private-value', $error);
        $this->assertStringContainsString('[redacted]', $error);
    }

    public function test_healthy_certificate_outside_renewal_window_is_not_reissued_when_auto_is_enabled(): void
    {
        $active = array_replace($this->active(), ['expires_at' => date(DATE_ATOM, time() + 60 * 86400)]);
        $machine = $this->machine($active);
        $this->report($machine);
        $this->assertSame($active['certificate_pem'], $machine->fresh()->subproxy_cert_state['certificate_pem']);
        $this->assertArrayNotHasKey('pending_certificate', $machine->fresh()->subproxy_cert_state);
        $this->assertSame(0, $this->acme->orders);
        Http::assertNothingSent();
    }

    private function active(): array
    {
        return [
            'provider' => 'zerossl', 'certificate_id' => 'old-issued',
            'domain' => '203.0.113.10', 'csr_hash' => hash('sha256', self::CSR),
            'status' => 'issued', 'expires_at' => date(DATE_ATOM, time() + 3 * 86400),
            'certificate_pem' => 'previous-leaf', 'ca_bundle_pem' => 'previous-chain',
            'probe' => ['status' => 'ok', 'last_success_at' => time()],
        ];
    }

    private function machine(array $state = [], array $extra = []): ServerMachine
    {
        $machine = ServerMachine::create(['name' => 'test proxy', 'token' => 'fixture-token', 'is_active' => true]);
        $machine->forceFill(array_replace([
            'subproxy_enabled' => true, 'subproxy_cert_domain' => '203.0.113.10',
            'subproxy_cert_state' => $state,
        ], $extra))->save();
        return $machine;
    }

    private function report(ServerMachine $machine, bool $ready = false, string $id = ''): void
    {
        app(ZeroSslCertificateService::class)->handleMachineStatus($machine, [
            'agent' => ['subscription_proxy' => [
                'certificate_domain' => '203.0.113.10', 'csr_pem' => self::CSR,
                'validation_ready' => $ready, 'certificate_id' => $id,
            ]],
        ]);
    }

    private function settings(array $extra = []): void
    {
        $values = array_replace([
            'subscription_proxy_enable' => true, 'zerossl_access_key' => 'test-key',
            'subscription_proxy_certificate_provider' => 'auto',
        ], $extra);
        app()->instance(Setting::class, new class($values) extends Setting {
            public function __construct(private array $values) {}
            public function get(string $key, mixed $default = null): mixed { return $this->values[$key] ?? $default; }
        });
    }
}

final class FailoverAcmeClient extends LetsEncryptAcmeClient
{
    public int $orders = 0;
    public int $downloads = 0;
    public bool $failCreate = false;
    public bool $failDownload = false;
    public bool $issued = false;

    public function createOrder(string $identifier): array
    {
        $this->orders++;
        if ($this->failCreate) { throw new \RuntimeException('ACME unavailable'); }
        return ['order_url' => 'https://acme.test/order/1', 'authorizations' => ['https://acme.test/authz/1'], 'finalize' => 'https://acme.test/order/1/finalize'];
    }

    public function fetch(string $url): array
    {
        if (str_contains($url, '/authz/')) {
            return ['status' => $this->issued ? 'valid' : 'pending', 'challenges' => [['type' => 'http-01', 'url' => 'https://acme.test/challenge/1', 'token' => 'token-1']]];
        }
        return $this->issued ? ['status' => 'valid', 'certificate' => 'https://acme.test/certificate/1'] : ['status' => 'pending'];
    }

    public function triggerChallenge(string $url): array { return []; }
    public function accountThumbprint(): string { return 'fixture-thumbprint'; }

    public function downloadCertificate(string $url): string
    {
        $this->downloads++;
        if ($this->failDownload) { throw new \RuntimeException('download unavailable'); }
        // A public CA PEM exercises the parser only; no real issuance or TLS occurs.
        $pem = (new \ReflectionClass(ZeroSslCertificateService::class))->getConstant('SECTIGO_R46_USERTRUST_CROSS_SIGNED_PEM');
        return $pem . "\n" . $pem;
    }
}
