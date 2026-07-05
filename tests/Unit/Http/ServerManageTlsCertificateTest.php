<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\Controllers\V2\Admin\Server\ManageController;
use App\Models\Server;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class ServerManageTlsCertificateTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpInMemoryDatabase();
        app()->instance('db.schema', $this->database->getConnection()->getSchemaBuilder());
        $this->bindJsonResponseFactory();
        $this->createTables();
    }

    public function test_get_nodes_exposes_hysteria_tls_certificate_fingerprint_status(): void
    {
        $server = Server::create([
            'type' => Server::TYPE_HYSTERIA,
            'runtime' => Server::RUNTIME_V2NODE,
            'name' => 'HY2 Tokyo',
            'host' => 'tokyo.example.test',
            'port' => '443',
            'server_port' => 443,
            'machine_id' => 9,
            'group_ids' => [],
            'route_ids' => [],
            'tags' => [],
            'rate' => 1,
            'protocol_settings' => [
                'version' => 2,
                'tls' => [
                    'server_name' => 'hy.example.test',
                    'allow_insecure' => true,
                ],
            ],
            'show' => true,
            'enabled' => true,
            'sort' => 0,
        ]);

        DB::table('v2_server_tls_certificate')->insert([
            'server_id' => $server->id,
            'machine_id' => 9,
            'protocol' => 'hysteria2',
            'sni' => 'hy.example.test',
            'status' => 'valid',
            'sha256_hex' => str_repeat('a', 64),
            'sha256_base64' => base64_encode(hex2bin(str_repeat('a', 64))),
            'changed_at' => '2026-07-05 10:00:00',
            'reported_at' => '2026-07-05 10:01:00',
            'created_at' => '2026-07-05 10:01:00',
            'updated_at' => '2026-07-05 10:01:00',
        ]);

        $response = (new ManageController())->getNodes(Request::create('/api/v2/admin/server/manage/getNodes'));
        $payload = $response->getData(true);
        $node = $payload['data'][0] ?? [];

        $this->assertSame($server->id, $node['id'] ?? null);
        $this->assertSame([
            [
                'machine_id' => 9,
                'protocol' => 'hysteria2',
                'sni' => 'hy.example.test',
                'status' => 'valid',
                'sha256_base64' => base64_encode(hex2bin(str_repeat('a', 64))),
                'changed_at' => '2026-07-05 10:00:00',
                'reported_at' => '2026-07-05 10:01:00',
            ],
        ], $node['tls_certificates'] ?? null);
    }

    private function createTables(): void
    {
        Schema::create('v2_server', function (Blueprint $table): void {
            $table->id();
            $table->string('type');
            $table->string('runtime')->default(Server::RUNTIME_GENERIC);
            $table->string('code')->nullable();
            $table->unsignedInteger('parent_id')->nullable();
            $table->unsignedBigInteger('machine_id')->nullable();
            $table->json('group_ids')->nullable();
            $table->json('route_ids')->nullable();
            $table->json('tags')->nullable();
            $table->string('name');
            $table->decimal('rate', 8, 2)->default(1);
            $table->string('host');
            $table->string('port');
            $table->integer('server_port');
            $table->json('protocol_settings')->nullable();
            $table->boolean('show')->default(true);
            $table->boolean('enabled')->default(true);
            $table->integer('sort')->nullable();
            $table->json('rate_time_ranges')->nullable();
            $table->boolean('rate_time_enable')->default(false);
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
