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

    public function test_handle_machine_status_refreshes_state_after_lock_to_avoid_duplicate_certificates(): void
    {
        $creates = 0;
        Http::fake(function ($request) use (&$creates) {
            if ($request->method() === 'POST' && str_contains($request->url(), '/certificates?')) {
                $creates++;
                return Http::response([
                    'id' => 'cert-' . $creates,
                    'status' => 'draft',
                    'expires' => '2026-10-01',
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
                'expires' => '2026-10-01',
            ]);
        });

        $machine = $this->createMachine();
        $staleMachine = ServerMachine::findOrFail($machine->id);
        $service = app(ZeroSslCertificateService::class);

        $service->handleMachineStatus($machine, $this->statusPayload(false));
        $service->handleMachineStatus($staleMachine, $this->statusPayload(false));

        $this->assertSame(1, $creates);
        $this->assertSame('cert-1', ServerMachine::find($machine->id)?->subproxy_cert_state['certificate_id']);
    }

    public function test_handle_machine_status_ignores_csr_from_duplicate_machine_identity(): void
    {
        $creates = 0;
        Http::fake(function ($request) use (&$creates) {
            if ($request->method() === 'POST' && str_contains($request->url(), '/certificates?')) {
                $creates++;
                return Http::response([
                    'id' => 'cert-' . $creates,
                    'status' => 'draft',
                    'expires' => '2026-10-01',
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
                'expires' => '2026-10-01',
            ]);
        });

        $machine = $this->createMachine();
        $firstStatus = $this->statusPayload(false);
        $firstStatus['system']['hostname'] = 'unknown';
        $firstStatus['ip']['public_ipv4'] = '2.56.116.39';
        $duplicateStatus = $this->statusPayload(false);
        $duplicateStatus['system']['hostname'] = 'unknown';
        $duplicateStatus['ip']['public_ipv4'] = '139.28.232.249';
        $duplicateStatus['agent']['subscription_proxy']['csr_pem'] = '-----BEGIN CERTIFICATE REQUEST-----duplicate-----END CERTIFICATE REQUEST-----';
        $service = app(ZeroSslCertificateService::class);

        $service->handleMachineStatus($machine, $firstStatus);
        $service->handleMachineStatus($machine->fresh(), $duplicateStatus);

        $state = ServerMachine::find($machine->id)?->subproxy_cert_state;
        $this->assertSame(1, $creates);
        $this->assertSame('cert-1', $state['certificate_id']);
        $this->assertSame('ipv4:2.56.116.39', $state['agent_identity']);
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

    public function test_handle_machine_status_waits_when_agent_validation_certificate_is_stale(): void
    {
        Http::fake();

        $machine = $this->createMachine([
            'subproxy_cert_state' => [
                'provider' => 'zerossl',
                'certificate_id' => 'cert-new',
                'domain' => '203.0.113.10',
                'csr_hash' => hash('sha256', '-----BEGIN CERTIFICATE REQUEST-----test-----END CERTIFICATE REQUEST-----'),
                'status' => 'draft',
                'validation_path' => '/.well-known/pki-validation/new.txt',
                'validation_content' => ['new-a', 'new-b'],
            ],
        ]);

        app(ZeroSslCertificateService::class)->handleMachineStatus($machine, $this->statusPayload(true, 'cert-old'));

        Http::assertNothingSent();

        $state = ServerMachine::find($machine->id)?->subproxy_cert_state;
        $this->assertSame('waiting_agent_reload', $state['status']);
        $this->assertStringContainsString('cert-old', $state['last_error']);
        $this->assertStringContainsString('cert-new', $state['last_error']);
    }

    public function test_handle_machine_status_does_not_request_validation_again_when_pending(): void
    {
        $machine = $this->createMachine([
            'subproxy_cert_state' => [
                'provider' => 'zerossl',
                'certificate_id' => 'cert-1',
                'domain' => '203.0.113.10',
                'csr_hash' => hash('sha256', '-----BEGIN CERTIFICATE REQUEST-----test-----END CERTIFICATE REQUEST-----'),
                'status' => 'pending_validation',
                'validation_path' => '/.well-known/pki-validation/token.txt',
                'validation_content' => ['line-a', 'line-b'],
                'validation_requested_at' => now()->subMinutes(5)->toIso8601String(),
            ],
        ]);

        Http::fake([
            'https://api.zerossl.com/certificates/cert-1?*' => Http::response([
                'id' => 'cert-1',
                'status' => 'pending_validation',
                'expires' => '2026-07-01',
            ]),
        ]);

        app(ZeroSslCertificateService::class)->handleMachineStatus($machine, $this->statusPayload(true, 'cert-1'));

        Http::assertNotSent(function ($request): bool {
            return $request->method() === 'POST' && str_contains($request->url(), '/certificates/cert-1/challenges?');
        });

        $state = ServerMachine::find($machine->id)?->subproxy_cert_state;
        $this->assertSame('pending_validation', $state['status']);
        $this->assertArrayHasKey('validation_requested_at', $state);
    }

    public function test_handle_machine_status_redownloads_issued_certificate_when_ca_bundle_is_missing(): void
    {
        $machine = $this->createMachine([
            'subproxy_cert_state' => [
                'provider' => 'zerossl',
                'certificate_id' => 'cert-1',
                'domain' => '203.0.113.10',
                'csr_hash' => hash('sha256', '-----BEGIN CERTIFICATE REQUEST-----test-----END CERTIFICATE REQUEST-----'),
                'status' => 'issued',
                'certificate_pem' => "-----BEGIN CERTIFICATE-----\nleaf\n-----END CERTIFICATE-----",
                'ca_bundle_pem' => '',
            ],
        ]);

        Http::fake([
            'https://api.zerossl.com/certificates/cert-1/download/json?*' => Http::response([
                'certificate.crt' => "-----BEGIN CERTIFICATE-----\nleaf\n-----END CERTIFICATE-----",
                'ca_bundle.crt' => "-----BEGIN CERTIFICATE-----\nca\n-----END CERTIFICATE-----",
            ]),
        ]);

        app(ZeroSslCertificateService::class)->handleMachineStatus($machine, $this->statusPayload(true, 'cert-1'));

        $state = ServerMachine::find($machine->id)?->subproxy_cert_state;
        $this->assertStringContainsString('leaf', $state['certificate_pem']);
        $this->assertStringContainsString('ca', $state['ca_bundle_pem']);
        $this->assertArrayHasKey('downloaded_at', $state);
    }

    public function test_handle_machine_status_appends_legacy_java_compatibility_chain_to_downloaded_ca_bundle(): void
    {
        $machine = $this->createMachine([
            'subproxy_cert_state' => [
                'provider' => 'zerossl',
                'certificate_id' => 'cert-1',
                'domain' => '203.0.113.10',
                'csr_hash' => hash('sha256', '-----BEGIN CERTIFICATE REQUEST-----test-----END CERTIFICATE REQUEST-----'),
                'status' => 'issued',
            ],
        ]);

        Http::fake([
            'https://api.zerossl.com/certificates/cert-1/download/json?*' => Http::response([
                'certificate.crt' => "-----BEGIN CERTIFICATE-----\nleaf\n-----END CERTIFICATE-----",
                'ca_bundle.crt' => "-----BEGIN CERTIFICATE-----\nzero-ssl-ca\n-----END CERTIFICATE-----",
            ]),
        ]);

        app(ZeroSslCertificateService::class)->handleMachineStatus($machine, $this->statusPayload(true, 'cert-1'));

        $state = ServerMachine::find($machine->id)?->subproxy_cert_state;
        $this->assertStringContainsString('zero-ssl-ca', $state['ca_bundle_pem']);
        $this->assertSame(2, substr_count($state['ca_bundle_pem'], '-----BEGIN CERTIFICATE-----'));
    }

    public function test_handle_machine_status_splits_fullchain_certificate_download_when_ca_bundle_is_empty(): void
    {
        $machine = $this->createMachine([
            'subproxy_cert_state' => [
                'provider' => 'zerossl',
                'certificate_id' => 'cert-1',
                'domain' => '203.0.113.10',
                'csr_hash' => hash('sha256', '-----BEGIN CERTIFICATE REQUEST-----test-----END CERTIFICATE REQUEST-----'),
                'status' => 'issued',
            ],
        ]);

        Http::fake([
            'https://api.zerossl.com/certificates/cert-1/download/json?*' => Http::response([
                'certificate.crt' => implode("\n", [
                    '-----BEGIN CERTIFICATE-----',
                    'leaf',
                    '-----END CERTIFICATE-----',
                    '-----BEGIN CERTIFICATE-----',
                    'compat-ca',
                    '-----END CERTIFICATE-----',
                ]),
                'ca_bundle.crt' => '',
            ]),
        ]);

        app(ZeroSslCertificateService::class)->handleMachineStatus($machine, $this->statusPayload(true, 'cert-1'));

        $state = ServerMachine::find($machine->id)?->subproxy_cert_state;
        $this->assertSame("-----BEGIN CERTIFICATE-----\nleaf\n-----END CERTIFICATE-----", $state['certificate_pem']);
        $this->assertStringContainsString("-----BEGIN CERTIFICATE-----\ncompat-ca\n-----END CERTIFICATE-----", $state['ca_bundle_pem']);
        $this->assertSame(2, substr_count($state['ca_bundle_pem'], '-----BEGIN CERTIFICATE-----'));
    }

    public function test_handle_machine_status_clears_last_error_after_successful_certificate_refresh(): void
    {
        $machine = $this->createMachine([
            'subproxy_cert_state' => [
                'provider' => 'zerossl',
                'certificate_id' => 'cert-1',
                'domain' => '203.0.113.10',
                'csr_hash' => hash('sha256', '-----BEGIN CERTIFICATE REQUEST-----test-----END CERTIFICATE REQUEST-----'),
                'status' => 'pending_validation',
                'validation_path' => '/.well-known/pki-validation/token.txt',
                'validation_content' => ['line-a', 'line-b'],
                'last_error' => 'ZeroSSL subscription proxy certificate automation requires an IPv4 address.',
            ],
        ]);

        Http::fake([
            'https://api.zerossl.com/certificates/cert-1?*' => Http::response([
                'id' => 'cert-1',
                'status' => 'pending_validation',
                'expires' => '2026-07-01',
            ]),
        ]);

        app(ZeroSslCertificateService::class)->handleMachineStatus($machine, $this->statusPayload(true, 'cert-1'));

        $state = ServerMachine::find($machine->id)?->subproxy_cert_state;
        $this->assertSame('pending_validation', $state['status']);
        $this->assertNull($state['last_error']);
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

    public function test_handle_machine_status_records_missing_access_key_instead_of_staying_silent(): void
    {
        $this->bindSettings([
            'subscription_proxy_enable' => true,
            'zerossl_access_key' => '',
        ]);
        Http::fake();

        $machine = $this->createMachine();
        $reload = app(ZeroSslCertificateService::class)->handleMachineStatus($machine, $this->statusPayload(false));

        Http::assertNothingSent();
        $fresh = ServerMachine::find($machine->id);
        $this->assertFalse($reload);
        $this->assertSame('missing_access_key', $fresh?->subproxy_cert_state['status'] ?? null);
        $this->assertStringContainsString('ZeroSSL access key', $fresh?->subproxy_cert_state['last_error'] ?? '');
    }

    public function test_handle_machine_status_runs_for_website_proxy_only_machine(): void
    {
        $this->bindSettings([
            'subscription_proxy_enable' => false,
            'website_proxy_enable' => false,
            'zerossl_access_key' => '',
        ]);
        Http::fake();

        $machine = $this->createMachine([
            'subproxy_enabled' => false,
            'webproxy_enabled' => true,
        ]);
        $reload = app(ZeroSslCertificateService::class)->handleMachineStatus($machine, $this->statusPayload(false));

        Http::assertNothingSent();
        $fresh = ServerMachine::find($machine->id);
        $this->assertFalse($reload);
        $this->assertSame('missing_access_key', $fresh?->subproxy_cert_state['status'] ?? null);
        $this->assertSame('203.0.113.10', $fresh?->subproxy_cert_state['domain'] ?? null);
    }

    public function test_handle_machine_status_does_not_rewrite_unchanged_missing_access_key_state(): void
    {
        $this->bindSettings([
            'subscription_proxy_enable' => true,
            'zerossl_access_key' => '',
        ]);
        Http::fake();

        $machine = $this->createMachine([
            'subproxy_cert_state' => [
                'provider' => 'zerossl',
                'status' => 'missing_access_key',
                'domain' => '203.0.113.10',
                'domain_source' => 'manual',
                'last_error' => 'ZeroSSL access key is not configured.',
                'updated_at' => '2026-06-11T10:00:00+00:00',
            ],
        ]);

        app(ZeroSslCertificateService::class)->handleMachineStatus($machine, $this->statusPayload(false));

        $fresh = ServerMachine::find($machine->id);
        $this->assertSame('2026-06-11T10:00:00+00:00', $fresh?->subproxy_cert_state['updated_at'] ?? null);
    }

    public function test_handle_machine_status_rejects_ipv6_certificate_domain_without_zerossl_request(): void
    {
        Http::fake();

        $machine = $this->createMachine([
            'subproxy_cert_domain' => null,
        ]);

        app(ZeroSslCertificateService::class)->handleMachineStatus($machine, [
            'agent' => [
                'subscription_proxy' => [
                    'certificate_domain' => '2607:f358:1a:e::d4d9:5831',
                    'csr_pem' => '-----BEGIN CERTIFICATE REQUEST-----ipv6-----END CERTIFICATE REQUEST-----',
                    'validation_ready' => false,
                ],
            ],
        ]);

        Http::assertNothingSent();
        $fresh = ServerMachine::find($machine->id);
        $this->assertSame('unsupported_certificate_domain', $fresh?->subproxy_cert_state['status'] ?? null);
        $this->assertStringContainsString('IPv4', $fresh?->subproxy_cert_state['last_error'] ?? '');
    }

    private function createTables(): void
    {
        Schema::create('v2_server_machine', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('token');
            $table->boolean('is_active')->default(true);
            $table->boolean('subproxy_enabled')->default(false);
            $table->boolean('webproxy_enabled')->default(false);
            $table->string('webproxy_path_prefix')->nullable();
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

    private function statusPayload(bool $validationReady, string $certificateId = ''): array
    {
        return [
            'agent' => [
                'subscription_proxy' => [
                    'certificate_domain' => '203.0.113.10',
                    'certificate_id' => $certificateId !== '' ? $certificateId : ($validationReady ? 'cert-1' : ''),
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
