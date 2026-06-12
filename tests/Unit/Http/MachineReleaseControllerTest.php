<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\Controllers\V2\Server\MachineReleaseController;
use App\Models\ServerMachine;
use App\Services\ServerMachine\MachineReleaseDistributionService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class MachineReleaseControllerTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpInMemoryDatabase();
        app()->instance('db.schema', $this->database->getConnection()->getSchemaBuilder());
        $this->createTables();
    }

    public function test_manifest_requires_valid_machine_token(): void
    {
        $machine = ServerMachine::create([
            'name' => 'edge',
            'token' => 'secret-token',
            'is_active' => true,
        ]);

        $controller = new MachineReleaseController(app(MachineReleaseDistributionService::class));
        $request = Request::create('/server/machine/releases/kelinode-rs/v0.1.292/linux-x86_64/manifest.json', 'GET', [
            'machine_id' => $machine->id,
            'machine_token' => 'wrong',
        ]);

        $response = $controller->manifest($request, 'kelinode-rs', 'v0.1.292', 'linux-x86_64');

        $this->assertSame(403, $response->getStatusCode());
    }

    public function test_manifest_streams_local_release_manifest_for_valid_machine(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put(
            'kelinode-rs/releases/kelinode-rs/v0.1.292/linux-x86_64/keli-native-node-v0.1.292-linux-x86_64.manifest.json',
            '{"component":"kelinode-rs","version":"v0.1.292","platform":"linux-x86_64","asset":"keli-native-node-v0.1.292-linux-x86_64.tar.gz","binary":"kelinode","sha256":"0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef"}'
        );

        $machine = ServerMachine::create([
            'name' => 'edge',
            'token' => 'secret-token',
            'is_active' => true,
        ]);

        $controller = new MachineReleaseController(app(MachineReleaseDistributionService::class));
        $request = Request::create('/server/machine/releases/kelinode-rs/v0.1.292/linux-x86_64/manifest.json', 'GET', [
            'machine_id' => $machine->id,
            'machine_token' => 'secret-token',
        ]);

        $response = $controller->manifest($request, 'kelinode-rs', 'v0.1.292', 'linux-x86_64');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('"sha256"', $response->getContent());
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
