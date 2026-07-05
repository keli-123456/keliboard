<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Server;
use App\Models\ServerMachine;
use App\Models\ServerTlsCertificate;
use App\Protocols\General;
use App\Services\ServerTlsCertificateService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class ServerTlsCertificateServiceTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpInMemoryDatabase();
        app()->instance('db.schema', $this->database->getConnection()->getSchemaBuilder());
        $this->createTables();
    }

    public function test_machine_status_stores_hy2_certificate_fingerprint_idempotently(): void
    {
        [$machine, $server] = $this->createMachineAndServer();
        $hex = str_repeat('a', 64);

        $first = (new ServerTlsCertificateService())->handleMachineStatus($machine, [
            'tls_certificates' => [
                $this->certificateRow($server->id, $machine->id, $hex),
            ],
        ]);

        $this->assertTrue($first['changed']);
        $this->assertSame(1, $first['stored']);
        $record = ServerTlsCertificate::query()->first();
        $this->assertSame($server->id, $record?->server_id);
        $this->assertSame($machine->id, $record?->machine_id);
        $this->assertSame('hysteria2', $record?->protocol);
        $this->assertSame('hy-sni.example.com', $record?->sni);
        $this->assertSame('valid', $record?->status);
        $this->assertSame($hex, $record?->sha256_hex);
        $this->assertSame(base64_encode(hex2bin($hex)), $record?->sha256_base64);

        $second = (new ServerTlsCertificateService())->handleMachineStatus($machine, [
            'tls_certificates' => [
                $this->certificateRow($server->id, $machine->id, strtoupper($hex)),
            ],
        ]);

        $this->assertFalse($second['changed']);
        $this->assertSame(1, ServerTlsCertificate::query()->count());
    }

    public function test_transient_invalid_report_does_not_replace_last_valid_fingerprint(): void
    {
        [$machine, $server] = $this->createMachineAndServer();
        $hex = str_repeat('b', 64);

        (new ServerTlsCertificateService())->handleMachineStatus($machine, [
            'tls_certificates' => [
                $this->certificateRow($server->id, $machine->id, $hex),
            ],
        ]);

        $invalid = (new ServerTlsCertificateService())->handleMachineStatus($machine, [
            'tls_certificates' => [
                [
                    'node_id' => $server->id,
                    'machine_id' => $machine->id,
                    'protocol' => 'hysteria2',
                    'sni' => 'hy-sni.example.com',
                    'status' => 'missing',
                    'sha256_hex' => '',
                ],
            ],
        ]);

        $this->assertFalse($invalid['changed']);
        $record = ServerTlsCertificate::query()->first();
        $this->assertSame('valid', $record?->status);
        $this->assertSame($hex, $record?->sha256_hex);
    }

    public function test_general_hy2_subscription_exports_pinned_peer_cert_sha256_when_available(): void
    {
        [$machine, $server] = $this->createMachineAndServer();
        $hex = str_repeat('c', 64);
        $pin = base64_encode(hex2bin($hex));

        (new ServerTlsCertificateService())->handleMachineStatus($machine, [
            'tls_certificates' => [
                $this->certificateRow($server->id, $machine->id, $hex),
            ],
        ]);

        $uri = General::buildHysteria('secret', [
            'id' => $server->id,
            'name' => 'General Hy2',
            'host' => 'hy.example.com',
            'port' => 8443,
            'protocol_settings' => [
                'version' => 2,
                'tls' => [
                    'server_name' => 'hy-sni.example.com',
                    'allow_insecure' => true,
                ],
            ],
        ]);

        $this->assertStringContainsString('pinnedPeerCertSha256=' . rawurlencode($pin), $uri);
        $this->assertStringContainsString('insecure=1', $uri);
    }

    private function createMachineAndServer(): array
    {
        $machine = ServerMachine::create([
            'name' => 'edge-a',
            'token' => ServerMachine::generateToken(),
            'is_active' => true,
        ]);

        $server = Server::create([
            'type' => Server::TYPE_HYSTERIA,
            'runtime' => Server::RUNTIME_V2NODE,
            'machine_id' => $machine->id,
            'group_ids' => [],
            'route_ids' => [],
            'name' => 'HY2',
            'rate' => 1,
            'tags' => [],
            'host' => 'hy.example.com',
            'port' => '8443',
            'server_port' => 8443,
            'protocol_settings' => [
                'version' => 2,
                'tls' => [
                    'server_name' => 'hy-sni.example.com',
                    'allow_insecure' => true,
                ],
            ],
            'show' => true,
            'enabled' => true,
            'sort' => 0,
        ]);

        return [$machine, $server];
    }

    private function certificateRow(int $serverId, int $machineId, string $sha256Hex): array
    {
        return [
            'node_id' => $serverId,
            'machine_id' => $machineId,
            'tag' => 'hysteria2:node-' . $serverId,
            'protocol' => 'hysteria2',
            'sni' => 'HY-SNI.EXAMPLE.COM',
            'status' => 'valid',
            'sha256_hex' => $sha256Hex,
        ];
    }

    private function createTables(): void
    {
        Schema::create('v2_server_machine', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('token');
            $table->boolean('is_active')->default(true);
            $table->integer('sort')->default(0);
            $table->unsignedInteger('last_seen_at')->nullable();
            $table->json('load_status')->nullable();
            $table->json('upgrade_state')->nullable();
            $table->timestamps();
        });

        Schema::create('v2_server', function (Blueprint $table): void {
            $table->id();
            $table->string('type');
            $table->string('runtime')->default(Server::RUNTIME_GENERIC);
            $table->string('code')->nullable();
            $table->unsignedInteger('parent_id')->nullable();
            $table->unsignedBigInteger('machine_id')->nullable();
            $table->json('group_ids')->nullable();
            $table->json('route_ids')->nullable();
            $table->string('name');
            $table->decimal('rate', 8, 2)->default(1);
            $table->json('tags')->nullable();
            $table->string('host');
            $table->string('port');
            $table->integer('server_port');
            $table->json('protocol_settings')->nullable();
            $table->boolean('show')->default(true);
            $table->boolean('enabled')->default(true);
            $table->integer('sort')->nullable();
            $table->timestamps();
        });

        Schema::create('v2_server_tls_certificate', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('server_id');
            $table->unsignedBigInteger('machine_id');
            $table->string('protocol', 32);
            $table->string('sni', 255)->default('');
            $table->string('status', 32)->default('valid');
            $table->string('sha256_hex', 64)->nullable();
            $table->string('sha256_base64', 128)->nullable();
            $table->timestamp('changed_at')->nullable();
            $table->timestamp('reported_at')->nullable();
            $table->timestamps();
        });
    }
}
