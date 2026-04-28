<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\Controllers\V2\Admin\Server\MachineController;
use App\Models\ServerMachine;
use App\Support\Setting;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class MachineControllerInstallCommandTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    private object $settings;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpInMemoryDatabase();
        app()->instance('db.schema', $this->database->getConnection()->getSchemaBuilder());
        $this->bindJsonResponseFactory();
        $this->bindSettings();
        $this->createTables();
    }

    public function test_install_command_returns_one_click_machine_install_command(): void
    {
        $machine = ServerMachine::create([
            'name' => "edge 'hk",
            'token' => "tok'en",
            'is_active' => true,
        ]);

        $request = $this->installRequest('https://panel.example.test/admin/server/machine/install', [
            'id' => $machine->id,
        ]);

        $response = (new MachineController())->installCommand($request);
        $payload = $response->getData(true);
        $command = $payload['data']['command'];

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringStartsWith(
            "curl -fsSL 'https://raw.githubusercontent.com/keli-123456/kelinode/main/script/install.sh' -o /tmp/v2node-install.sh && bash /tmp/v2node-install.sh",
            $command
        );
        $this->assertStringContainsString("--machine-url 'https://panel.example.test'", $command);
        $this->assertStringContainsString('--machine-id ' . $machine->id, $command);
        $this->assertStringContainsString("--machine-token 'tok'\"'\"'en'", $command);
        $this->assertStringContainsString("--machine-name 'edge '\"'\"'hk'", $command);
    }

    public function test_install_command_uses_configured_node_api_base_url(): void
    {
        $this->settings->values['node_api_base_url'] = 'https://node-api.example.test/';
        $machine = ServerMachine::create([
            'name' => 'edge-vn',
            'token' => 'machine-token',
            'is_active' => true,
        ]);

        $request = $this->installRequest('https://panel.example.test/admin/server/machine/install', [
            'id' => $machine->id,
        ]);

        $response = (new MachineController())->installCommand($request);
        $payload = $response->getData(true);
        $command = $payload['data']['command'];
        $config = $payload['data']['config'];

        $this->assertStringContainsString("--machine-url 'https://node-api.example.test'", $command);
        $this->assertStringNotContainsString("--machine-url 'https://panel.example.test'", $command);
        $this->assertStringContainsString('      url: "https://node-api.example.test"', $config);
    }

    private function bindSettings(): void
    {
        $this->settings = new class {
            /** @var array<string, mixed> */
            public array $values = [];

            public function get(string $key, mixed $default = null): mixed
            {
                return $this->values[$key] ?? $default;
            }
        };

        app()->instance(Setting::class, $this->settings);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function installRequest(string $url, array $payload): Request
    {
        $base = Request::create($url, 'POST', $payload);
        $request = new class extends Request {
            public function validate(array $rules, ...$params): array
            {
                return [];
            }
        };

        $request->initialize(
            $base->query->all(),
            $base->request->all(),
            $base->attributes->all(),
            $base->cookies->all(),
            $base->files->all(),
            $base->server->all(),
            $base->getContent()
        );
        $request->headers->replace($base->headers->all());

        return $request;
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
            $table->timestamps();
        });
    }
}
