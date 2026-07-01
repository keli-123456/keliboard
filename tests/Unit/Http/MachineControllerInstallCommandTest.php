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
use Illuminate\Support\Facades\Storage;
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
        $this->assertSame('kelinode', $payload['data']['default_agent']);
        $this->assertFalse($payload['data']['native_enabled']);
        $this->assertStringStartsWith("#!/usr/bin/env bash", $command);
        $this->assertStringContainsString('AGENT_TYPE="kelinode"', $command);
        $this->assertStringContainsString('MACHINE_URL="https://panel.example.test"', $command);
        $this->assertStringContainsString('MACHINE_ID="' . $machine->id . '"', $command);
        $this->assertStringContainsString("MACHINE_TOKEN=\"tok'en\"", $command);
        $this->assertStringContainsString("MACHINE_NAME=\"edge 'hk\"", $command);
        $this->assertStringContainsString('KELINODE_INSTALL_SCRIPT_URL="https://raw.githubusercontent.com/keli-123456/kelinode/main/script/install.sh"', $command);
        $commands = collect($payload['data']['install_commands'] ?? [])->keyBy('agent');
        $this->assertSame(['kelinode', 'kelinode-rs'], $commands->keys()->all());
        $this->assertTrue((bool) $commands['kelinode']['is_default']);
        $this->assertFalse((bool) $commands['kelinode-rs']['is_default']);
        $this->assertStringStartsWith("#!/usr/bin/env bash", $commands['kelinode']['command']);
        $this->assertStringContainsString('AGENT_TYPE="kelinode"', $commands['kelinode']['command']);
        $this->assertStringStartsWith("#!/usr/bin/env bash", $commands['kelinode-rs']['command']);
        $this->assertStringContainsString('AGENT_TYPE="kelinode-rs"', $commands['kelinode-rs']['command']);
        $this->assertArrayNotHasKey('native_command', $payload['data']);
        $this->assertArrayNotHasKey('native_config', $payload['data']);
    }

    public function test_install_command_uses_native_command_as_default_when_kelinode_rs_enabled(): void
    {
        $this->settings->values['server_machine_default_agent'] = 'kelinode-rs';
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
        $config = $payload['data']['config'];
        $nativeCommand = $payload['data']['native_command'];
        $nativeUninstallCommand = $payload['data']['native_uninstall_command'];
        $nativeLogCommand = $payload['data']['native_log_command'];
        $nativeConfig = $payload['data']['native_config'];
        $legacyCommand = $payload['data']['legacy_command'];
        $legacyConfig = $payload['data']['legacy_config'];

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('kelinode-rs', $payload['data']['default_agent']);
        $this->assertTrue($payload['data']['native_enabled']);
        $this->assertSame('latest', $payload['data']['native_version']);
        $this->assertStringStartsWith("#!/usr/bin/env bash", $command);
        $this->assertStringContainsString('AGENT_TYPE="kelinode-rs"', $command);
        $this->assertSame($command, $nativeCommand);
        $this->assertStringNotContainsString('--version', $command);
        $this->assertStringContainsString('MACHINE_URL="https://panel.example.test"', $command);
        $this->assertStringContainsString('MACHINE_ID="' . $machine->id . '"', $command);
        $this->assertStringContainsString("MACHINE_TOKEN=\"tok'en\"", $command);
        $this->assertStringContainsString("MACHINE_NAME=\"edge 'hk\"", $command);
        $this->assertStringContainsString('KELINODE_RS_INSTALL_SCRIPT_URL="https://raw.githubusercontent.com/keli-123456/kelinode-rs/main/script/install.sh"', $command);
        $this->assertStringContainsString('kernel:', $config);
        $this->assertStringContainsString('  type: keli-core-rs', $config);
        $this->assertSame($config, $nativeConfig);
        $this->assertStringStartsWith(
            "curl -fsSL 'https://raw.githubusercontent.com/keli-123456/kelinode-rs/main/script/install.sh' -o /tmp/keli-native-node-install.sh && bash /tmp/keli-native-node-install.sh uninstall",
            $nativeUninstallCommand
        );
        $this->assertStringNotContainsString((string) $machine->token, $nativeUninstallCommand);
        $this->assertSame('kelinode log', $nativeLogCommand);
        $this->assertStringContainsString('kernel:', $nativeConfig);
        $this->assertStringContainsString('  type: keli-core-rs', $nativeConfig);
        $this->assertStringContainsString('  config_dir: "/etc/kelinode"', $nativeConfig);
        $this->assertStringContainsString('      config_dir: "/etc/kelinode"', $nativeConfig);
        $this->assertStringContainsString('      token: "tok\'en"', $nativeConfig);
        $this->assertStringStartsWith("#!/usr/bin/env bash", $legacyCommand);
        $this->assertStringContainsString('AGENT_TYPE="kelinode"', $legacyCommand);
        $this->assertStringContainsString('machine:', $legacyConfig);
        $this->assertStringNotContainsString('kernel:', $legacyConfig);
        $commands = collect($payload['data']['install_commands'] ?? [])->keyBy('agent');
        $this->assertFalse((bool) $commands['kelinode']['is_default']);
        $this->assertTrue((bool) $commands['kelinode-rs']['is_default']);
        $this->assertSame($legacyCommand, $commands['kelinode']['command']);
        $this->assertSame($nativeCommand, $commands['kelinode-rs']['command']);
    }

    public function test_install_command_uses_panel_distribution_source_setting(): void
    {
        $this->settings->values['server_machine_default_agent'] = 'kelinode-rs';
        $this->settings->values['server_machine_distribution_source'] = 'panel';

        $machine = ServerMachine::create([
            'name' => 'edge-panel',
            'token' => 'machine-token',
            'is_active' => true,
        ]);

        $request = $this->installRequest('https://panel.example.test/admin/server/machine/install', [
            'id' => $machine->id,
        ]);

        $response = (new MachineController())->installCommand($request);
        $payload = $response->getData(true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringStartsWith("#!/usr/bin/env bash", $payload['data']['command']);
        $this->assertStringContainsString('KELINODE_RS_INSTALL_SCRIPT_URL="https://panel.example.test/api/v2/server/machine/kelinode-rs/install.sh"', $payload['data']['command']);
        $this->assertStringContainsString('KELINODE_RS_RELEASE_BASE_URL="https://panel.example.test/api/v2/server/machine/releases"', $payload['data']['command']);
        $this->assertStringContainsString('MACHINE_ID="' . $machine->id . '"', $payload['data']['command']);
        $this->assertStringContainsString('MACHINE_TOKEN="machine-token"', $payload['data']['command']);
        $this->assertSame('panel', $payload['data']['distribution_source']);
    }

    public function test_install_command_uses_custom_distribution_base_url(): void
    {
        $this->settings->values['server_machine_default_agent'] = 'kelinode-rs';
        $this->settings->values['server_machine_distribution_source'] = 'custom';
        $this->settings->values['server_machine_distribution_base_url'] = 'https://mirror.example.test/keli';

        $machine = ServerMachine::create([
            'name' => 'edge-custom',
            'token' => 'machine-token',
            'is_active' => true,
        ]);

        $request = $this->installRequest('https://panel.example.test/admin/server/machine/install', [
            'id' => $machine->id,
        ]);

        $payload = (new MachineController())->installCommand($request)->getData(true);

        $this->assertStringContainsString('KELINODE_RS_INSTALL_SCRIPT_URL="https://mirror.example.test/keli/kelinode-rs/install.sh"', $payload['data']['command']);
        $this->assertStringContainsString('KELINODE_RS_RELEASE_BASE_URL="https://mirror.example.test/keli/releases"', $payload['data']['command']);
        $this->assertSame('custom', $payload['data']['distribution_source']);
    }

    public function test_install_command_uses_configured_node_api_base_url(): void
    {
        $this->settings->values['node_api_base_url'] = 'https://node-api.example.test/';
        $this->settings->values['server_machine_default_agent'] = 'kelinode-rs';
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
        $legacyCommand = $payload['data']['legacy_command'];

        $this->assertStringContainsString('MACHINE_URL="https://node-api.example.test"', $command);
        $this->assertStringNotContainsString('MACHINE_URL="https://panel.example.test"', $command);
        $this->assertStringContainsString('      url: "https://node-api.example.test"', $config);
        $this->assertStringContainsString('      url: "https://node-api.example.test"', $nativeConfig);
        $this->assertStringContainsString('MACHINE_URL="https://node-api.example.test"', $legacyCommand);
    }

    public function test_fetch_includes_agent_online_status(): void
    {
        ServerMachine::create([
            'name' => 'edge-online',
            'token' => 'token-a',
            'is_active' => true,
            'last_seen_at' => time() - 60,
        ]);
        ServerMachine::create([
            'name' => 'edge-offline',
            'token' => 'token-b',
            'is_active' => true,
            'last_seen_at' => time() - 600,
        ]);
        ServerMachine::create([
            'name' => 'edge-never',
            'token' => 'token-c',
            'is_active' => true,
            'last_seen_at' => null,
        ]);

        $response = (new MachineController())->fetch(Request::create('/admin/server/machine/fetch', 'GET'));
        $rows = collect($response->getData(true)['data'])->keyBy('name');

        $this->assertTrue($rows['edge-online']['is_online']);
        $this->assertSame('online', $rows['edge-online']['online_status']);
        $this->assertFalse($rows['edge-offline']['is_online']);
        $this->assertSame('offline', $rows['edge-offline']['online_status']);
        $this->assertFalse($rows['edge-never']['is_online']);
        $this->assertSame('never', $rows['edge-never']['online_status']);
        $this->assertSame(300, $rows['edge-online']['online_threshold_seconds']);
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

    public function test_version_info_uses_panel_local_release_when_distribution_source_is_panel(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put(
            'kelinode-rs/releases/kelinode-rs/v0.1.292/linux-x86_64/keli-native-node-v0.1.292-linux-x86_64.manifest.json',
            '{"component":"kelinode-rs","version":"v0.1.292","platform":"linux-x86_64","asset":"keli-native-node-v0.1.292-linux-x86_64.tar.gz","binary":"kelinode","sha256":"0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef"}'
        );
        $this->settings->values['server_machine_distribution_source'] = 'panel';

        $request = $this->installRequest('https://panel.example.test/admin/server/machine/versionInfo', [
            'component' => 'kelinode-rs',
            'force' => true,
        ]);

        $payload = (new MachineController())->versionInfo($request)->getData(true);

        $this->assertSame('v0.1.292', $payload['data']['latest_version']);
        $this->assertSame('panel', $payload['data']['source']);
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

        Schema::create('v2_server', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('machine_id')->nullable();
        });
    }
}
