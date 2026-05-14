<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\Controllers\V2\Admin\Server\MachineController;
use App\Models\ServerMachine;
use App\Support\Setting;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
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
        $nativeCommand = $payload['data']['native_command'];
        $nativeConfig = $payload['data']['native_config'];

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringStartsWith(
            "curl -fsSL 'https://raw.githubusercontent.com/keli-123456/kelinode/main/script/install.sh' -o /tmp/v2node-install.sh && bash /tmp/v2node-install.sh",
            $command
        );
        $this->assertStringContainsString("--machine-url 'https://panel.example.test'", $command);
        $this->assertStringContainsString('--machine-id ' . $machine->id, $command);
        $this->assertStringContainsString("--machine-token 'tok'\"'\"'en'", $command);
        $this->assertStringContainsString("--machine-name 'edge '\"'\"'hk'", $command);

        $this->assertSame('v0.1.26', $payload['data']['native_version']);
        $this->assertStringStartsWith(
            "curl -fsSL 'https://raw.githubusercontent.com/keli-123456/kelinode-rs/main/script/install.sh' -o /tmp/keli-native-node-install.sh && bash /tmp/keli-native-node-install.sh",
            $nativeCommand
        );
        $this->assertStringContainsString("--version 'v0.1.26'", $nativeCommand);
        $this->assertStringContainsString("--machine-url 'https://panel.example.test'", $nativeCommand);
        $this->assertStringContainsString('--machine-id ' . $machine->id, $nativeCommand);
        $this->assertStringContainsString("--machine-token 'tok'\"'\"'en'", $nativeCommand);
        $this->assertStringContainsString("--machine-name 'edge '\"'\"'hk'", $nativeCommand);
        $this->assertStringContainsString('kernel:', $nativeConfig);
        $this->assertStringContainsString('  type: keli-core-rs', $nativeConfig);
        $this->assertStringContainsString('  config_dir: "/etc/v2node"', $nativeConfig);
        $this->assertStringContainsString('      config_dir: "/etc/v2node"', $nativeConfig);
        $this->assertStringContainsString('      token: "tok\'en"', $nativeConfig);
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
        $nativeConfig = $payload['data']['native_config'];

        $this->assertStringContainsString("--machine-url 'https://node-api.example.test'", $command);
        $this->assertStringNotContainsString("--machine-url 'https://panel.example.test'", $command);
        $this->assertStringContainsString('      url: "https://node-api.example.test"', $config);
        $this->assertStringContainsString('      url: "https://node-api.example.test"', $nativeConfig);
    }

    public function test_upgrade_rejects_existing_in_progress_task_without_force(): void
    {
        $machine = ServerMachine::create([
            'name' => 'edge-upgrade',
            'token' => 'machine-token',
            'is_active' => true,
            'upgrade_state' => [
                'status' => 'running',
                'target_version' => 'v0.3.24',
            ],
        ]);

        $request = $this->installRequest('https://panel.example.test/admin/server/machine/upgrade', [
            'id' => $machine->id,
            'target_version' => 'v0.3.25',
        ]);

        $response = (new MachineController())->upgrade($request);
        $payload = $response->getData(true);

        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame('fail', $payload['status']);
        $this->assertSame('该机器已有进行中的升级任务', $payload['message']);
    }

    public function test_version_info_uses_component_release_repository(): void
    {
        Http::fake([
            'https://api.github.com/repos/keli-123456/kelinode-rs/releases?per_page=20' => Http::response([
                [
                    'tag_name' => 'v0.1.4',
                    'prerelease' => true,
                    'draft' => false,
                ],
            ]),
        ]);

        $request = $this->installRequest('https://panel.example.test/admin/server/machine/versionInfo', [
            'component' => 'kelinode-rs',
            'force' => true,
        ]);

        $response = (new MachineController())->versionInfo($request);
        $payload = $response->getData(true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('v0.1.4', $payload['data']['latest_version']);
        $this->assertSame('kelinode-rs', $payload['data']['component']);
        $this->assertSame('kelinode-rs', $payload['data']['repository']);
    }

    public function test_upgrade_queues_component_specific_target_version(): void
    {
        Http::fake([
            'https://api.github.com/repos/keli-123456/keli-core-rs/releases?per_page=20' => Http::response([
                [
                    'tag_name' => 'v0.1.1',
                    'prerelease' => true,
                    'draft' => false,
                ],
            ]),
        ]);

        $machine = ServerMachine::create([
            'name' => 'edge-core',
            'token' => 'machine-token',
            'is_active' => true,
            'load_status' => [
                'version' => 'v0.1.7',
            ],
        ]);

        $request = $this->installRequest('https://panel.example.test/admin/server/machine/upgrade', [
            'id' => $machine->id,
            'component' => 'core',
        ]);

        $response = (new MachineController())->upgrade($request);
        $payload = $response->getData(true);
        $state = $payload['data']['upgrade_state'];

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('queued', $state['status']);
        $this->assertSame('core', $state['component']);
        $this->assertSame('v0.1.1', $state['target_version']);
    }

    public function test_upgrade_allows_native_component_when_runtime_agent_reports_native_node(): void
    {
        $machine = ServerMachine::create([
            'name' => 'edge-native-agent',
            'token' => 'machine-token',
            'is_active' => true,
            'load_status' => [
                'version' => 'v0.3.24',
                'runtime' => [
                    'agent' => 'kelinode-rs',
                ],
            ],
        ]);

        $request = $this->installRequest('https://panel.example.test/admin/server/machine/upgrade', [
            'id' => $machine->id,
            'component' => 'core',
            'target_version' => 'v0.1.1',
        ]);

        $response = (new MachineController())->upgrade($request);
        $payload = $response->getData(true);
        $state = $payload['data']['upgrade_state'];

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('queued', $state['status']);
        $this->assertSame('core', $state['component']);
        $this->assertSame('v0.1.1', $state['target_version']);
    }

    public function test_upgrade_rejects_native_component_on_legacy_machine(): void
    {
        $machine = ServerMachine::create([
            'name' => 'edge-legacy',
            'token' => 'machine-token',
            'is_active' => true,
            'load_status' => [
                'version' => 'v0.3.24',
            ],
        ]);

        $request = $this->installRequest('https://panel.example.test/admin/server/machine/upgrade', [
            'id' => $machine->id,
            'component' => 'core',
            'target_version' => 'v0.1.1',
        ]);

        $response = (new MachineController())->upgrade($request);
        $payload = $response->getData(true);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('该机器当前节点端不支持该组件升级，请先安装 kelinode-rs', $payload['message']);
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
                return $this->request->all();
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
            $table->json('upgrade_state')->nullable();
            $table->timestamps();
        });
    }
}
