<?php

namespace Tests\Unit\Http;

use App\Http\Controllers\V1\App\ConfigController;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class KeliCoreConfigTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpInMemoryDatabase();
        $this->bindJsonResponseFactory();
        $this->createUserTable();
        Schema::create('v2_server', function (Blueprint $table): void {
            $table->increments('id');
            foreach (['name', 'type', 'host', 'port', 'group_ids', 'protocol_settings'] as $field) $table->text($field);
            $table->boolean('show')->default(true);
            $table->boolean('enabled')->default(true);
            $table->integer('sort')->default(0);
            $table->integer('parent_id')->nullable();
            $table->integer('created_at')->default(0);
            $table->integer('updated_at')->default(0);
        });
        DB::table('v2_user')->insert(['id' => 1, 'email' => 'buyer@example.test', 'uuid' => '123e4567-e89b-12d3-a456-426614174000',
            'group_id' => 10, 'transfer_enable' => 1024, 'banned' => false]);
        foreach ([['mieru', 'Visible', '10'], ['trusttunnel', 'Unsupported', '10'], ['mieru', 'Other group', '11']] as [$type, $name, $group]) {
            DB::table('v2_server')->insert(['name' => $name, 'type' => $type, 'host' => 'node.example.test', 'port' => '1234',
                'group_ids' => json_encode([$group]), 'protocol_settings' => '{}']);
        }
    }

    public function test_native_endpoint_preserves_visibility_and_reports_unsupported_nodes(): void
    {
        $request = Request::create('/api/v1/app/config?core=kelicore&core_version=0.1.425&platform=android');
        $request->setUserResolver(fn () => User::find(1));
        $response = (new ConfigController())->config($request);
        $this->assertSame(200, $response->getStatusCode());
        $data = $response->getData(true)['data'];
        $this->assertSame(['Visible'], array_column($data['outbounds'], 'tag'));
        $this->assertSame(['Unsupported'], array_column($data['keli']['skipped'], 'name'));
        $this->assertSame('android', $data['keli']['platform']);
        $this->assertStringNotContainsString('Other group', $response->getContent());
    }

    public function test_unsupported_single_node_returns_its_reason_without_an_export_500(): void
    {
        $request = Request::create('/api/v1/app/config?core=kelicore&core_version=0.1.425&platform=macos&server_id=2');
        $request->setUserResolver(fn () => User::find(1));
        $response = (new ConfigController())->config($request);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([], $response->getData(true)['data']['outbounds']);
        $this->assertCount(1, $response->getData(true)['data']['keli']['skipped']);
    }

    public function test_unavailable_user_and_other_group_node_remain_denied(): void
    {
        $request = Request::create('/api/v1/app/config?core=kelicore&core_version=0.1.425&server_id=3');
        $request->setUserResolver(fn () => User::find(1));
        $this->assertSame(404, (new ConfigController())->config($request)->getStatusCode());
        DB::table('v2_user')->where('id', 1)->update(['banned' => true]);
        $this->assertSame(403, (new ConfigController())->config($request)->getStatusCode());
    }
}
