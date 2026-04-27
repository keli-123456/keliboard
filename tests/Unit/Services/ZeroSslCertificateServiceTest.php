<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\ServerMachine;
use App\Services\SubscriptionProxy\ZeroSslCertificateService;
use App\Support\Setting;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class ZeroSslCertificateServiceTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpInMemoryDatabase();
        app()->instance('db.schema', $this->database->getConnection()->getSchemaBuilder());
        $this->createTables();
        $this->bindSettings([
            'subscription_proxy_enable' => true,
            'zerossl_access_key' => 'test-key',
            'subscription_proxy_renew_days' => 20,
        ]);
    }

    public function test_handle_machine_status_creates_certificate_and_stores_http_validation(): void
    {
        Http::fake(function ($request) {
            $url = $request->url();
            if ($request->method() === 'POST' && str_contains($url, '/certificates?')) {
                return Http::response([
                    'id' => 'cert-1',
                    'status' => 'draft',
                    'expires' => '2026-07-01',
                    'validation' => [
                        'other_methods' => [
                            '203.0.113.10' => [
                                'file_validation_url_http' => 'http://203.0.113.10/.well-known/pki-validation/token.txt',
                                'file_validation_content' => ['line-a', 'line-b'],
                            ],
                        ],
                    ],
                ]);
            }

            return Http::response([
                'id' => 'cert-1',
                'status' => 'draft',
                'expires' => '2026-07-01',
            ]);
        });

        $machine = $this->createMachine();
        app(ZeroSslCertificateService::class)->handleMachineStatus($machine, $this->statusPayload(false));

        $state = ServerMachine::find($machine->id)?->subproxy_cert_state;
        $this->assertSame('cert-1', $state['certificate_id']);
        $this->assertSame('203.0.113.10', $state['domain']);
        $this->assertSame('draft', $state['status']);
        $this->assertSame('/.well-known/pki-validation/token.txt', $state['validation_path']);
        $this->assertSame(['line-a', 'line-b'], $state['validation_content']);
        $this->assertSame(hash('sha256', '-----BEGIN CERTIFICATE REQUEST-----test-----END CERTIFICATE REQUEST-----'), $state['csr_hash']);
    }

    public function test_handle_machine_status_requests_validation_and_downloads_issued_certificate(): void
    {
        $machine = $this->createMachine([
            'subproxy_cert_state' => [
                'provider' => 'zerossl',
                'certificate_id' => 'cert-1',
                'domain' => '203.0.113.10',
                'csr_hash' => hash('sha256', '-----BEGIN CERTIFICATE REQUEST-----test-----END CERTIFICATE REQUEST-----'),
                'status' => 'draft',
                'validation_path' => '/.well-known/pki-validation/token.txt',
                'validation_content' => ['line-a', 'line-b'],
            ],
        ]);

        Http::fake(function ($request) {
            $url = $request->url();
            if ($request->method() === 'POST' && str_contains($url, '/certificates/cert-1/challenges?')) {
                return Http::response(['status' => 'pending_validation']);
            }
            if (str_contains($url, '/certificates/cert-1/download/json?')) {
                return Http::response([
                    'certificate.crt' => "-----BEGIN CERTIFICATE-----\nleaf\n-----END CERTIFICATE-----",
                    'ca_bundle.crt' => "-----BEGIN CERTIFICATE-----\nca\n-----END CERTIFICATE-----",
                ]);
            }

            return Http::response([
                'id' => 'cert-1',
                'status' => 'issued',
                'expires' => '2026-07-01',
            ]);
        });

        app(ZeroSslCertificateService::class)->handleMachineStatus($machine, $this->statusPayload(true));

        $state = ServerMachine::find($machine->id)?->subproxy_cert_state;
        $this->assertSame('issued', $state['status']);
        $this->assertStringContainsString('leaf', $state['certificate_pem']);
        $this->assertStringContainsString('ca', $state['ca_bundle_pem']);
        $this->assertArrayHasKey('validation_requested_at', $state);
    }

    public function test_handle_machine_status_renews_certificate_before_expiry(): void
    {
        $machine = $this->createMachine([
            'subproxy_cert_state' => [
                'provider' => 'zerossl',
                'certificate_id' => 'cert-old',
                'domain' => '203.0.113.10',
                'csr_hash' => hash('sha256', '-----BEGIN CERTIFICATE REQUEST-----test-----END CERTIFICATE REQUEST-----'),
                'status' => 'issued',
                'expires_at' => now()->addDays(5)->toDateTimeString(),
                'certificate_pem' => "-----BEGIN CERTIFICATE-----\nold\n-----END CERTIFICATE-----",
            ],
        ]);

        Http::fake(function ($request) {
            $url = $request->url();
            if ($request->method() === 'POST' && str_contains($url, '/certificates?')) {
                return Http::response([
                    'id' => 'cert-2',
                    'status' => 'draft',
                    'expires' => now()->addDays(90)->toDateTimeString(),
                    'validation' => [
                        'other_methods' => [
                            '203.0.113.10' => [
                                'file_validation_url_http' => 'http://203.0.113.10/.well-known/pki-validation/renew.txt',
                                'file_validation_content' => ['renew-a', 'renew-b'],
                            ],
                        ],
                    ],
                ]);
            }

            return Http::response([
                'id' => 'cert-2',
                'status' => 'draft',
                'expires' => now()->addDays(90)->toDateTimeString(),
            ]);
        });

        app(ZeroSslCertificateService::class)->handleMachineStatus($machine, $this->statusPayload(false));

        $state = ServerMachine::find($machine->id)?->subproxy_cert_state;
        $this->assertSame('cert-2', $state['certificate_id']);
        $this->assertSame('draft', $state['status']);
        $this->assertSame('/.well-known/pki-validation/renew.txt', $state['validation_path']);
        $this->assertArrayNotHasKey('certificate_pem', $state);
    }

    public function test_handle_machine_status_skips_zerossl_when_another_site_owns_certificate(): void
    {
        $this->bindSettings([
            'subscription_proxy_enable' => true,
            'subscription_proxy_site_id' => 'site-b',
            'zerossl_access_key' => 'test-key',
            'subscription_proxy_renew_days' => 20,
        ]);
        Http::fake();

        $machine = $this->createMachine([
            'subproxy_cert_state' => [
                'provider' => 'zerossl',
                'certificate_id' => 'stale-cert',
                'status' => 'draft',
                'validation_path' => '/.well-known/pki-validation/stale.txt',
                'validation_content' => ['stale'],
            ],
        ]);

        app(ZeroSslCertificateService::class)->handleMachineStatus($machine, [
            'agent' => [
                'subscription_proxy' => [
                    'certificate_domain' => '203.0.113.10',
                    'certificate_owner_site_id' => 'site-a',
                    'csr_pem' => '-----BEGIN CERTIFICATE REQUEST-----test-----END CERTIFICATE REQUEST-----',
                    'validation_ready' => false,
                ],
            ],
        ]);

        Http::assertNothingSent();

        $state = ServerMachine::find($machine->id)?->subproxy_cert_state;
        $this->assertSame('delegated', $state['status']);
        $this->assertSame('site-a', $state['certificate_owner_site_id']);
        $this->assertSame('203.0.113.10', $state['domain']);
        $this->assertNull($state['last_error']);
        $this->assertArrayNotHasKey('certificate_id', $state);
        $this->assertArrayNotHasKey('validation_path', $state);
    }

    public function test_handle_machine_status_uses_explicit_site_id_for_certificate_owner_delegation(): void
    {
        $this->bindSettings([
            'subscription_proxy_enable' => true,
            'zerossl_access_key' => 'test-key',
            'subscription_proxy_renew_days' => 20,
        ]);
        Http::fake();

        $machine = $this->createMachine();

        app(ZeroSslCertificateService::class)->handleMachineStatus($machine, [
            'agent' => [
                'subscription_proxy' => [
                    'certificate_domain' => '203.0.113.10',
                    'certificate_owner_site_id' => 'site-a',
                    'csr_pem' => '-----BEGIN CERTIFICATE REQUEST-----test-----END CERTIFICATE REQUEST-----',
                    'validation_ready' => false,
                ],
            ],
        ], 'site-b');

        Http::assertNothingSent();

        $state = ServerMachine::find($machine->id)?->subproxy_cert_state;
        $this->assertSame('delegated', $state['status']);
        $this->assertSame('site-a', $state['certificate_owner_site_id']);
        $this->assertSame('203.0.113.10', $state['domain']);
        $this->assertArrayNotHasKey('certificate_id', $state);
    }

    public function test_handle_machine_status_replaces_legacy_auto_certificate_ip(): void
    {
        Http::fake(function ($request) {
            $url = $request->url();
            if ($request->method() === 'POST' && str_contains($url, '/certificates?')) {
                return Http::response([
                    'id' => 'cert-new',
                    'status' => 'draft',
                    'expires' => '2026-07-01',
                    'validation' => [
                        'other_methods' => [
                            '198.51.100.20' => [
                                'file_validation_url_http' => 'http://198.51.100.20/.well-known/pki-validation/new.txt',
                                'file_validation_content' => ['new-a', 'new-b'],
                            ],
                        ],
                    ],
                ]);
            }

            return Http::response([
                'id' => 'cert-new',
                'status' => 'draft',
                'expires' => '2026-07-01',
            ]);
        });

        $machine = $this->createMachine([
            'subproxy_cert_domain' => '172.104.189.93',
            'subproxy_cert_state' => [
                'provider' => 'zerossl',
                'certificate_id' => 'cert-old',
                'domain' => '172.104.189.93',
                'status' => 'draft',
            ],
        ]);

        app(ZeroSslCertificateService::class)->handleMachineStatus($machine, [
            'agent' => [
                'subscription_proxy' => [
                    'certificate_domain' => '198.51.100.20',
                    'csr_pem' => '-----BEGIN CERTIFICATE REQUEST-----new-----END CERTIFICATE REQUEST-----',
                    'validation_ready' => false,
                ],
            ],
        ]);

        $fresh = ServerMachine::find($machine->id);
        $state = $fresh?->subproxy_cert_state;
        $this->assertNull($fresh?->subproxy_cert_domain);
        $this->assertSame('cert-new', $state['certificate_id']);
        $this->assertSame('198.51.100.20', $state['domain']);
        $this->assertSame('auto', $state['domain_source']);
        $this->assertSame('/.well-known/pki-validation/new.txt', $state['validation_path']);
    }

    public function test_handle_machine_status_keeps_configured_domain_when_agent_reports_stale_domain(): void
    {
        Http::fake();

        $machine = $this->createMachine([
            'subproxy_cert_domain' => '152.53.135.140',
            'subproxy_cert_state' => [
                'last_error' => 'old error',
            ],
        ]);

        app(ZeroSslCertificateService::class)->handleMachineStatus($machine, [
            'agent' => [
                'subscription_proxy' => [
                    'certificate_domain' => '2400:8901::2000:49ff:fe93:6d50',
                    'csr_pem' => '-----BEGIN CERTIFICATE REQUEST-----ipv6-----END CERTIFICATE REQUEST-----',
                    'validation_ready' => false,
                ],
            ],
        ]);

        Http::assertNothingSent();

        $fresh = ServerMachine::find($machine->id);
        $this->assertSame('152.53.135.140', $fresh?->subproxy_cert_domain);
        $this->assertSame('waiting_agent_reload', $fresh?->subproxy_cert_state['status'] ?? null);
        $this->assertStringContainsString('2400:8901::2000:49ff:fe93:6d50', $fresh?->subproxy_cert_state['last_error'] ?? '');
    }

    private function createTables(): void
    {
        Schema::create('v2_server_machine', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('token');
            $table->boolean('is_active')->default(true);
            $table->boolean('subproxy_enabled')->default(false);
            $table->unsignedSmallInteger('subproxy_https_port')->nullable();
            $table->unsignedSmallInteger('subproxy_http_port')->nullable();
            $table->string('subproxy_cert_domain')->nullable();
            $table->json('subproxy_cert_state')->nullable();
            $table->integer('sort')->default(0);
            $table->unsignedInteger('last_seen_at')->nullable();
            $table->json('load_status')->nullable();
            $table->timestamps();
        });
    }

    private function createMachine(array $overrides = []): ServerMachine
    {
        $machine = ServerMachine::create(array_merge([
            'name' => 'edge-a',
            'token' => 'machine-token',
            'is_active' => true,
        ], $overrides));
        $machine->forceFill(array_merge([
            'subproxy_enabled' => true,
            'subproxy_cert_domain' => '203.0.113.10',
        ], $overrides))->save();

        return $machine->fresh();
    }

    private function statusPayload(bool $validationReady): array
    {
        return [
            'agent' => [
                'subscription_proxy' => [
                    'certificate_domain' => '203.0.113.10',
                    'csr_pem' => '-----BEGIN CERTIFICATE REQUEST-----test-----END CERTIFICATE REQUEST-----',
                    'validation_ready' => $validationReady,
                ],
            ],
        ];
    }

    private function bindSettings(array $values): void
    {
        app()->instance(Setting::class, new class($values) extends Setting {
            private array $values;

            public function __construct(array $values)
            {
                $this->values = array_change_key_case($values, CASE_LOWER);
            }

            public function get(string $key, mixed $default = null): mixed
            {
                return $this->values[strtolower($key)] ?? $default;
            }
        });
    }
}
