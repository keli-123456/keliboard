<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\EmailTemplateService;
use Illuminate\View\ViewServiceProvider;
use Illuminate\Validation\ValidationException;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class EmailTemplateServiceTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['view.paths' => [resource_path('views')], 'view.compiled' => storage_path('framework/views')]);
        app()->register(ViewServiceProvider::class);
        $this->bindTestSettings(['app_name' => 'Site <Brand>', 'email_template' => 'default']);
    }

    public function test_all_original_templates_can_be_edited_and_render_like_the_installed_views(): void
    {
        $service = new EmailTemplateService();
        foreach (['default', 'classic', 'breeze', 'letter', 'coral', 'midnight'] as $template) foreach (array_keys(EmailTemplateService::SUBJECTS) as $kind) {
            $data = $service->editor($template, $kind);
            $this->assertFalse($data['customized']);
            $service->validateDocument($kind, $data['defaults']);
            $rendered = $service->render($data['defaults'], $service->samples($kind));
            $this->assertSame($data['preview']['html'], $rendered['html']);
            $this->assertSame($data['preview']['subject'], $rendered['subject']);
        }
    }

    public function test_new_themes_have_no_external_assets_and_can_be_saved_and_restored_independently(): void
    {
        $service = new EmailTemplateService();
        foreach (['breeze', 'letter', 'coral', 'midnight'] as $template) {
            foreach (array_keys(EmailTemplateService::SUBJECTS) as $kind) {
                $original = $service->editor($template, $kind);
                $this->assertStringNotContainsString('@include', $original['html']);
                $this->assertStringNotContainsString('@php', $original['html']);
                $this->assertStringContainsString('max-width:600px', $original['html']);
                $this->assertStringContainsString('prefers-color-scheme:dark', $original['html']);
                $this->assertStringNotContainsString('<script', $original['html']);
                $this->assertDoesNotMatchRegularExpression('/<(?:img|link|iframe)\b/i', $original['html']);
                $changed = ['subject' => 'Custom {{name}}', 'html' => $original['html'] . '<p>Custom footer</p>'];
                $saved = $service->save($template, $kind, $changed);
                $this->assertTrue($saved['customized']);
                $this->assertStringContainsString('Custom footer', $service->preview($template, $kind)['html']);
                $reset = $service->save($template, $kind, null);
                $this->assertSame($original, $reset);
            }
        }
        $this->assertSame('default', admin_setting('email_template'));
    }

    public function test_save_and_restore_are_isolated_to_one_template_kind_and_survive_new_service_instances(): void
    {
        $service = new EmailTemplateService();
        $original = $service->editor('default', 'verify');
        $service->save('default', 'notify', ['subject' => '{{subject}}', 'html' => '<p>{{content}}</p>']);
        $saved = $service->save('default', 'verify', ['subject' => 'Welcome {{name}}', 'html' => '<h1>{{name}}</h1><p>{{code}}</p>']);
        $this->assertTrue($saved['customized']);
        $this->assertSame('Welcome {{name}}', (new EmailTemplateService())->editor('default', 'verify')['subject']);
        $this->assertSame('Welcome Site <Brand>', $service->preview('default', 'verify')['subject']);
        $this->assertSame('<h1>Site &lt;Brand&gt;</h1><p>123456</p>', $service->preview('default', 'verify')['html']);
        $this->assertFalse($service->editor('classic', 'verify')['customized']);
        $restored = $service->save('default', 'verify', null);
        $this->assertFalse($restored['customized']);
        $this->assertSame($original['html'], $restored['html']);
        $this->assertTrue($service->editor('default', 'notify')['customized']);
        $this->assertSame('default', admin_setting('email_template'));
    }

    public function test_draft_preview_does_not_save_and_tokens_are_replaced_only_once(): void
    {
        $service = new EmailTemplateService();
        $preview = $service->preview('default', 'verify', ['subject' => 'Draft {{name}}', 'html' => '<p>{{code}}</p>']);
        $this->assertSame('<p>123456</p>', $preview['html']);
        $this->assertNull($service->override('default', 'verify'));
        $rendered = $service->render(['subject' => '{{name}}', 'html' => '<p>{{name}}</p><a href="{{url}}">Link</a>'], ['name' => "{{code}}\r\nInjected", 'url' => 'https://example.test/?a="&b=2', 'code' => 'secret']);
        $this->assertSame('{{code}}Injected', $rendered['subject']);
        $this->assertStringContainsString('{{code}}', $rendered['html']);
        $this->assertStringNotContainsString('secret', $rendered['html']);
        $this->assertStringContainsString('&quot;&amp;b=2', $rendered['html']);
    }

    public function test_overrides_are_persisted_in_settings_and_cache_is_invalidated(): void
    {
        $this->setUpInMemoryDatabase();
        app('db.schema')->create('v2_settings', function (\Illuminate\Database\Schema\Blueprint $table) {
            $table->increments('id'); $table->string('name')->unique(); $table->text('value')->nullable(); $table->timestamps();
        });
        app()->instance(\App\Support\Setting::class, new \App\Support\Setting());
        $service = new EmailTemplateService();
        $this->assertNull($service->override('default', 'verify'));
        $service->save('default', 'verify', ['subject' => 'Persisted', 'html' => '{{code}}']);
        app()->instance(\App\Support\Setting::class, new \App\Support\Setting());
        $this->assertSame('Persisted', $service->override('default', 'verify')['subject']);
        $service->save('default', 'verify', null);
        app()->instance(\App\Support\Setting::class, new \App\Support\Setting());
        $this->assertNull($service->override('default', 'verify'));
    }

    public function test_unsafe_templates_and_missing_required_variables_are_not_saved(): void
    {
        $service = new EmailTemplateService();
        foreach ([
            ['verify', 'subject', '<p>missing code</p>'], ['mailLogin', 'subject', '<p>missing link</p>'],
            ['notify', 'subject', '<p>missing content</p>'], ['verify', "Bad\r\nBcc: other", '{{code}}'],
            ['verify', 'subject', '<?php phpinfo(); ?>{{code}}'], ['verify', 'subject', '@php phpinfo(); @endphp{{code}}'],
            ['verify', 'subject', '{{system()}}{{code}}'], ['verify', 'subject', '{{code}}<script>alert(1)</script>'],
            ['verify', 'subject', '{{code}}<img src="x" onerror="alert(1)">'],
            ['verify', 'subject', '{{code}}<a href="javascript:alert(1)">Open</a>'],
            ['verify', 'subject', '{{code}}<a href="java&#9;script:alert(1)">Open</a>'],
            ['verify', 'subject', '{{code}}<a href="data:text/html;base64,AAA">Open</a>'],
            ['verify', 'subject', '{{code}}<meta http-equiv="refresh" content="0;url=https://example.test">'],
            ['verify', 'subject', '{{code}}<iframe src="https://example.test"></iframe>'],
            ['verify', 'subject', '{{code}}<form action="https://example.test"></form>'],
            ['verify', 'subject', '{{unknown}}{{code}}'], ['verify', 'subject', '{{code}}' . str_repeat('a', 100001)],
        ] as [$kind, $subject, $html]) {
            try { $service->save('default', $kind, compact('subject', 'html')); $this->fail('Unsafe document accepted'); }
            catch (ValidationException $error) { $this->assertNotEmpty($error->errors()); }
            $this->assertNull($service->override('default', $kind));
        }
    }
}
