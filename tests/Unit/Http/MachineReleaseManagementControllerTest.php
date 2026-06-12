<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\Controllers\V2\Admin\Server\MachineReleaseManagementController;
use App\Models\ServerMachineRelease;
use App\Services\ServerMachine\MachineReleaseDistributionService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class MachineReleaseManagementControllerTest extends TestCase
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

    public function test_upload_stores_release_and_sets_first_release_as_default(): void
    {
        Storage::fake('local');
        $archiveContent = 'binary-tarball';
        $sha256 = hash('sha256', $archiveContent);
        $manifestContent = $this->manifestJson('kelinode-rs', 'v0.1.308', 'linux-x86_64', $sha256);
        $request = Request::create('/admin/server/machine/release/upload', 'POST', [
            'component' => 'kelinode-rs',
            'version' => 'v0.1.308',
            'platform' => 'linux-x86_64',
        ], [], [
            'manifest' => $this->upload('keli-native-node-v0.1.308-linux-x86_64.manifest.json', $manifestContent),
            'archive' => $this->upload('keli-native-node-v0.1.308-linux-x86_64.tar.gz', $archiveContent),
        ]);

        $response = (new MachineReleaseManagementController(app(MachineReleaseDistributionService::class)))->upload($request);
        $payload = $response->getData(true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('v0.1.308', $payload['data']['version']);
        $this->assertTrue($payload['data']['is_default']);
        Storage::disk('local')->assertExists(
            'kelinode-rs/releases/kelinode-rs/v0.1.308/linux-x86_64/keli-native-node-v0.1.308-linux-x86_64.manifest.json'
        );
        Storage::disk('local')->assertExists(
            'kelinode-rs/releases/kelinode-rs/v0.1.308/linux-x86_64/keli-native-node-v0.1.308-linux-x86_64.tar.gz'
        );
        $this->assertSame('v0.1.308', app(MachineReleaseDistributionService::class)->latestLocalVersion('kelinode-rs', 'linux-x86_64'));
    }

    public function test_upload_accepts_github_release_manifest_name_field(): void
    {
        Storage::fake('local');
        $archiveContent = 'native-node-tarball';
        $sha256 = hash('sha256', $archiveContent);
        $manifestContent = $this->manifestJsonWithName('kelinode-rs', 'v0.1.308', 'linux-x86_64', $sha256);
        $request = Request::create('/admin/server/machine/release/upload', 'POST', [
            'component' => 'kelinode-rs',
            'version' => 'v0.1.308',
            'platform' => 'linux-x86_64',
        ], [], [
            'manifest' => $this->upload('keli-native-node-v0.1.308-linux-x86_64.manifest.json', $manifestContent),
            'archive' => $this->upload('keli-native-node-v0.1.308-linux-x86_64.tar.gz', $archiveContent),
        ]);

        $response = (new MachineReleaseManagementController(app(MachineReleaseDistributionService::class)))->upload($request);
        $payload = $response->getData(true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('v0.1.308', $payload['data']['version']);
        $this->assertSame('kelinode-rs', $payload['data']['component']);
    }

    public function test_set_default_changes_latest_local_version(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('old.manifest.json', '{}');
        Storage::disk('local')->put('old.tar.gz', 'old');
        Storage::disk('local')->put('new.manifest.json', '{}');
        Storage::disk('local')->put('new.tar.gz', 'new');

        ServerMachineRelease::create([
            'component' => 'kelinode-rs',
            'version' => 'v0.1.307',
            'platform' => 'linux-x86_64',
            'manifest_path' => 'old.manifest.json',
            'archive_path' => 'old.tar.gz',
            'sha256' => str_repeat('a', 64),
            'size' => 12,
            'is_default' => true,
            'status' => ServerMachineRelease::STATUS_ACTIVE,
        ]);
        $newer = ServerMachineRelease::create([
            'component' => 'kelinode-rs',
            'version' => 'v0.1.308',
            'platform' => 'linux-x86_64',
            'manifest_path' => 'new.manifest.json',
            'archive_path' => 'new.tar.gz',
            'sha256' => str_repeat('b', 64),
            'size' => 15,
            'is_default' => false,
            'status' => ServerMachineRelease::STATUS_ACTIVE,
        ]);

        $request = Request::create('/admin/server/machine/release/setDefault', 'POST', [
            'id' => $newer->id,
        ]);

        $response = (new MachineReleaseManagementController(app(MachineReleaseDistributionService::class)))->setDefault($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('v0.1.308', app(MachineReleaseDistributionService::class)->latestLocalVersion('kelinode-rs', 'linux-x86_64'));
        $this->assertFalse((bool) ServerMachineRelease::query()->where('version', 'v0.1.307')->value('is_default'));
    }

    public function test_drop_rejects_default_release(): void
    {
        $release = ServerMachineRelease::create([
            'component' => 'kelinode-rs',
            'version' => 'v0.1.308',
            'platform' => 'linux-x86_64',
            'manifest_path' => 'manifest.json',
            'archive_path' => 'archive.tar.gz',
            'sha256' => str_repeat('c', 64),
            'size' => 20,
            'is_default' => true,
            'status' => ServerMachineRelease::STATUS_ACTIVE,
        ]);

        $request = Request::create('/admin/server/machine/release/drop', 'POST', [
            'id' => $release->id,
        ]);

        $response = (new MachineReleaseManagementController(app(MachineReleaseDistributionService::class)))->drop($request);
        $payload = $response->getData(true);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('默认版本不能删除，请先切换默认版本', $payload['message']);
        $this->assertNotNull(ServerMachineRelease::find($release->id));
    }

    private function createTables(): void
    {
        Schema::create('v2_server_machine_release', function (Blueprint $table): void {
            $table->id();
            $table->string('component', 32);
            $table->string('version', 64);
            $table->string('platform', 32);
            $table->string('manifest_path', 512);
            $table->string('archive_path', 512);
            $table->char('sha256', 64);
            $table->unsignedBigInteger('size')->default(0);
            $table->boolean('is_default')->default(false);
            $table->string('status', 16)->default('active');
            $table->timestamps();
        });
    }

    private function upload(string $name, string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'keli-release-');
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, null, null, true);
    }

    private function manifestJson(string $component, string $version, string $platform, string $sha256): string
    {
        return json_encode([
            'component' => $component,
            'version' => $version,
            'platform' => $platform,
            'asset' => ($component === 'keli-core-rs' ? 'keli-core-rs' : 'keli-native-node') . '-' . $version . '-' . $platform . '.tar.gz',
            'binary' => $component === 'keli-core-rs' ? 'keli-core-rs' : 'kelinode',
            'sha256' => $sha256,
        ], JSON_THROW_ON_ERROR);
    }

    private function manifestJsonWithName(string $name, string $version, string $platform, string $sha256): string
    {
        return json_encode([
            'name' => $name,
            'version' => $version,
            'platform' => $platform,
            'archive' => 'keli-native-node-' . $version . '-' . $platform . '.tar.gz',
            'sha256' => $sha256,
            'binary' => 'kelinode',
            'binaries' => [
                'agent' => 'bin/kelinode',
            ],
        ], JSON_THROW_ON_ERROR);
    }
}
