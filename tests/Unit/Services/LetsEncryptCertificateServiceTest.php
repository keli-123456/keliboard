<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\ServerMachine;
use App\Services\SubscriptionProxy\LetsEncryptAcmeClient;
use App\Services\SubscriptionProxy\ZeroSslCertificateService;
use App\Support\Setting;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class LetsEncryptCertificateServiceTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

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

        app()->instance(Setting::class, new class extends Setting {
            public function __construct() {}

            public function get(string $key, mixed $default = null): mixed
            {
                return [
                    'subscription_proxy_enable' => true,
                    'subscription_proxy_certificate_provider' => 'letsencrypt',
                    'letsencrypt_renew_hours' => 48,
                ][strtolower($key)] ?? $default;
            }
        });
    }

    public function test_creates_short_lived_ip_order_and_stores_acme_http_challenge(): void
    {
        app()->instance(LetsEncryptAcmeClient::class, new class extends LetsEncryptAcmeClient {
            public function createOrder(string $identifier): array
            {
                return [
                    'order_url' => 'https://acme.test/order/1',
                    'authorizations' => ['https://acme.test/authz/1'],
                    'finalize' => 'https://acme.test/order/1/finalize',
                    'status' => 'pending',
                ];
            }

            public function fetch(string $url): array
            {
                if (str_contains($url, '/authz/')) {
                    return [
                        'status' => 'pending',
                        'challenges' => [[
                            'type' => 'http-01',
                            'url' => 'https://acme.test/challenge/1',
                            'token' => 'token-1',
                        ]],
                    ];
                }
                return ['status' => 'pending'];
            }

            public function accountThumbprint(): string
            {
                return 'account-thumbprint';
            }
        });

        $machine = ServerMachine::create([
            'name' => 'edge-a',
            'token' => 'machine-token',
            'is_active' => true,
            'subproxy_enabled' => true,
            'subproxy_cert_domain' => '203.0.113.10',
        ]);

        app(ZeroSslCertificateService::class)->handleMachineStatus($machine, [
            'agent' => [
                'subscription_proxy' => [
                    'certificate_domain' => '203.0.113.10',
                    'csr_pem' => '-----BEGIN CERTIFICATE REQUEST-----test-----END CERTIFICATE REQUEST-----',
                    'validation_ready' => false,
                ],
            ],
        ]);

        $state = ServerMachine::findOrFail($machine->id)->subproxy_cert_state;
        $this->assertSame('letsencrypt', $state['provider']);
        $this->assertSame('https://acme.test/order/1', $state['certificate_id']);
        $this->assertSame('/.well-known/acme-challenge/token-1', $state['validation_path']);
        $this->assertSame('token-1.account-thumbprint', $state['validation_content']);
    }
}
