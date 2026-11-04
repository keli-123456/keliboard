<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\Controllers\V2\Admin\PlanController;
use App\Http\Requests\Admin\PlanContentPreview;
use App\Http\Requests\Admin\PlanSave;
use App\Http\Resources\PlanResource;
use App\Http\Routes\V2\AdminRoute;
use App\Models\Plan;
use Illuminate\Events\Dispatcher;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\DB;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use Mockery;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class PlanContentPreviewTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpInMemoryDatabase();
        $this->createPlanTable();
        $this->bindJsonResponseFactory();
        $this->bindTestSettings(['secure_path' => 'local-admin', 'reset_traffic_method' => Plan::RESET_TRAFFIC_NEVER]);
    }

    public function test_preview_uses_the_storefront_formatter_without_saving_a_plan(): void
    {
        foreach ([null, Plan::RESET_TRAFFIC_MONTHLY, Plan::RESET_TRAFFIC_NEVER] as $reset) {
            $draft = ['content' => '<p>{{transfer}} GB / {{speed}} / {{devices}} / {{reset_method}}</p>',
                'transfer_enable' => 250, 'speed_limit' => null, 'device_limit' => 3, 'reset_traffic_method' => $reset];
            $request = Mockery::mock(PlanContentPreview::class)->makePartial();
            $request->shouldReceive('validated')->once()->andReturn($draft);
            DB::flushQueryLog();
            DB::enableQueryLog();
            $response = (new PlanController())->previewContent($request)->getData(true);
            $this->assertSame([], DB::getQueryLog());
            DB::disableQueryLog();
            $this->assertSame((new PlanResource($draft))->resolve($request)['content'], $response['data']['content']);
            $this->assertStringContainsString('250 GB', $response['data']['content']);
            $this->assertStringNotContainsString('{{', $response['data']['content']);
        }
        $this->assertSame(0, Plan::query()->count());
    }

    public function test_preview_accepts_empty_content_and_validates_draft_parameters(): void
    {
        $factory = new Factory(new Translator(new ArrayLoader(), 'en'));
        $rules = (new PlanContentPreview())->rules();
        foreach (['', '<h2>Notes</h2>', '{{transfer}} GB'] as $content) {
            $this->assertTrue($factory->make(['content' => $content, 'transfer_enable' => 100], $rules)->passes());
        }
        foreach ([['transfer_enable' => 0], ['transfer_enable' => 100, 'speed_limit' => -1],
            ['transfer_enable' => 100, 'reset_traffic_method' => 99], ['transfer_enable' => 100, 'content' => str_repeat('a', 200001)]] as $draft) {
            $this->assertTrue($factory->make($draft, $rules)->fails());
        }
    }

    public function test_preview_route_is_post_only_and_keeps_admin_authentication(): void
    {
        config()->set('app.key', 'local-preview-test');
        $router = new Router(new Dispatcher(app()), app());
        (new AdminRoute())->map($router);
        $route = collect($router->getRoutes()->getRoutes())->first(fn ($route) => str_ends_with($route->uri(), '/plan/previewContent'));
        $this->assertNotNull($route);
        $this->assertSame(['POST'], $route->methods());
        $this->assertContains('admin', $route->gatherMiddleware());
    }

    public function test_an_explicit_empty_description_clears_the_existing_plan_content(): void
    {
        $plan = Plan::query()->create(['name' => 'Local draft', 'content' => '<p>Old content</p>', 'transfer_enable' => 100]);
        $request = Mockery::mock(PlanSave::class)->makePartial();
        $request->shouldReceive('validated')->once()->andReturn(['content' => '']);
        $request->shouldReceive('input')->with('id')->andReturn($plan->id);
        $request->shouldReceive('boolean')->with('force_update')->andReturn(false);
        $response = (new PlanController())->save($request)->getData(true);
        $this->assertTrue($response['data']);
        $this->assertSame('', $plan->fresh()->content);
        $this->assertSame(100, $plan->fresh()->transfer_enable);
    }
}
