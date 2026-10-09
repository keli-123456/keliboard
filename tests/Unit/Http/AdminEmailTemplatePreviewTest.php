<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\Controllers\V2\Admin\ConfigController;
use Illuminate\Http\Request;
use Illuminate\Routing\RoutingServiceProvider;
use Illuminate\Validation\ValidationException;
use Illuminate\View\ViewServiceProvider;
use Psr\Log\NullLogger;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class AdminEmailTemplatePreviewTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->instance('request', Request::create('https://preview.example.invalid'));
        config(['app.key' => 'email-preview-test-key', 'view.paths' => [resource_path('views')], 'view.compiled' => storage_path('framework/views')]);
        app()->register(ViewServiceProvider::class);
        app()->register(RoutingServiceProvider::class);
        app()->instance('log', new NullLogger());
        $this->bindTestSettings(['app_name' => 'Preview <Site>', 'email_template' => 'default', 'email_password' => 'never-expose']);
        // A preview must not resolve the delivery, queue, or transport services.
        foreach (['mailer', 'mail.manager', 'queue', 'App\Services\MessageDispatchService'] as $service) {
            app()->bind($service, static function () { throw new \LogicException('Preview attempted email delivery'); });
        }
    }

    public function test_renders_every_bundled_template_with_samples_without_saving_or_sending(): void
    {
        foreach (['default', 'classic', 'breeze', 'letter', 'coral', 'midnight'] as $template) {
            foreach (['remindExpire', 'remindTraffic', 'verify', 'mailLogin', 'notify'] as $kind) {
                $response = (new ConfigController())->previewEmailTemplate(Request::create('/', 'GET', compact('template', 'kind')));
                $this->assertSame(200, $response->getStatusCode());
                $data = $response->getData(true)['data'];
                $this->assertSame($template, $data['template']);
                $this->assertSame($kind, $data['kind']);
                $this->assertStringContainsString('Preview &lt;Site&gt;', $data['html']);
                $this->assertStringContainsString('https://preview.example.invalid', $data['html']);
                $this->assertStringNotContainsString('never-expose', $data['html']);
                $this->assertStringNotContainsString('{{$', $data['html']);
                $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
                if ($kind === 'verify') $this->assertStringContainsString('123456', $data['html']);
            }
        }
        $this->assertSame('default', admin_setting('email_template'));
    }

    public function test_template_picker_lists_six_themes_without_shared_layouts(): void
    {
        $data = (new ConfigController())->getEmailTemplate()->getData(true)['data'];
        foreach (['default', 'classic', 'breeze', 'letter', 'coral', 'midnight'] as $template) $this->assertContains($template, $data);
        $this->assertNotContains('mail-layouts', $data);
        $this->assertNotContains('message', $data);
        $this->assertSame('default', admin_setting('email_template'));
    }

    public function test_rejects_directory_traversal_and_unsupported_email_types(): void
    {
        foreach ([['template' => '../default', 'kind' => 'verify'], ['template' => 'default', 'kind' => '../../config'], ['template' => 'default/verify', 'kind' => 'notify'], ['template' => 'default', 'kind' => 'arbitrary'], []] as $input) {
            try {
                (new ConfigController())->previewEmailTemplate(Request::create('/', 'GET', $input));
                $this->fail('Expected preview input validation to reject this path');
            } catch (ValidationException $error) {
                $this->assertNotEmpty($error->errors());
            }
        }
    }

    public function test_missing_template_is_not_silently_replaced_with_default(): void
    {
        $response = (new ConfigController())->previewEmailTemplate(Request::create('/', 'GET', ['template' => 'missing-preview-template', 'kind' => 'verify']));
        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('fail', $response->getData(true)['status']);
    }

    public function test_preview_route_stays_in_the_admin_authenticated_config_group(): void
    {
        $router = app('router');
        (new \App\Http\Routes\V2\AdminRoute())->map($router);
        $route = collect($router->getRoutes())->first(fn ($route) => str_ends_with($route->uri(), '/config/previewEmailTemplate'));
        $this->assertNotNull($route);
        $this->assertContains('GET', $route->methods());
        $this->assertNotContains('POST', $route->methods());
        $this->assertContains('admin', $route->gatherMiddleware());
        foreach (['editEmailTemplate' => 'GET', 'previewEmailTemplateDraft' => 'POST', 'saveEmailTemplate' => 'POST'] as $name => $method) {
            $route = collect($router->getRoutes())->first(fn ($route) => str_ends_with($route->uri(), '/config/' . $name));
            $this->assertNotNull($route); $this->assertContains($method, $route->methods()); $this->assertContains('admin', $route->gatherMiddleware());
            if ($method === 'POST') $this->assertNotContains('GET', $route->methods());
        }
    }

    public function test_edit_preview_save_and_reset_endpoints_share_the_delivery_template(): void
    {
        $controller = new ConfigController();
        $input = ['template' => 'default', 'kind' => 'verify'];
        $original = $controller->editEmailTemplate(Request::create('/', 'GET', $input))->getData(true)['data'];
        $draft = [...$input, 'subject' => 'Draft {{name}}', 'html' => '<p>{{code}}</p>'];
        $preview = $controller->previewEmailTemplateDraft(Request::create('/', 'POST', $draft));
        $this->assertSame('<p>123456</p>', $preview->getData(true)['data']['html']);
        $this->assertFalse($controller->editEmailTemplate(Request::create('/', 'GET', $input))->getData(true)['data']['customized']);
        $saved = $controller->saveEmailTemplate(Request::create('/', 'POST', $draft))->getData(true)['data'];
        $this->assertTrue($saved['customized']);
        $this->assertSame($preview->getData(true)['data'], $controller->previewEmailTemplate(Request::create('/', 'GET', $input))->getData(true)['data']);
        $reset = $controller->saveEmailTemplate(Request::create('/', 'POST', [...$input, 'reset' => true]))->getData(true)['data'];
        $this->assertSame($original, $reset);
    }
}
