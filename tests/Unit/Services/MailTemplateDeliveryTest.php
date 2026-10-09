<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\MailLog;
use App\Models\MessageDispatchLog;
use App\Services\EmailTemplateService;
use App\Services\MailService;
use App\Services\MessageDispatchService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;
use Illuminate\View\ViewServiceProvider;
use Symfony\Component\Mime\Email;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class MailTemplateDeliveryTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpInMemoryDatabase();
        app('db.schema')->create('v2_mail_log', function (Blueprint $table) {
            $table->increments('id');
            foreach (['email', 'subject', 'template_name', 'error'] as $name) $table->text($name)->nullable();
            $table->integer('created_at')->nullable(); $table->integer('updated_at')->nullable();
        });
        $this->bindTestSettings(['email_template' => 'default', 'app_name' => 'Platform']);
        config(['view.paths' => [resource_path('views')], 'view.compiled' => storage_path('framework/views'), 'mail.from.address' => 'noreply@example.test']);
        app()->register(ViewServiceProvider::class);
        app()->instance(MessageDispatchService::class, new class extends MessageDispatchService {
            public function getProviderHealth(): array { return ['status' => 'healthy']; }
            public function checkImmediateQuota(string $messageType, string $channel = 'email'): array { return ['allowed' => true]; }
            public function logDispatchAttempt(array $attributes): MessageDispatchLog { return (new MessageDispatchLog())->forceFill(['id' => 123, ...$attributes]); }
        });
    }

    public function test_delivery_uses_saved_html_and_subject_but_keeps_original_path_after_restore(): void
    {
        $transport = new class {
            public array $calls = [];
            public function html($html, $callback): void { $this->capture('html', $html, $callback); }
            public function send($view, $values, $callback): void { $this->capture('view', $view, $callback); }
            private function capture($mode, $body, $callback): void {
                $email = new Email(); $callback(new Message($email));
                $this->calls[] = ['mode' => $mode, 'body' => $body, 'subject' => $email->getSubject(), 'from' => $email->getFrom()[0]->getName()];
            }
        };
        Mail::swap($transport);
        $templates = new EmailTemplateService();
        $templates->save('default', 'verify', ['subject' => '{{name}} verification', 'html' => '<p>{{name}}: {{code}}</p>']);
        $params = ['email' => 'recipient@example.test', 'subject' => 'Original subject', 'from_name' => 'Subsite', 'template_name' => 'verify', 'template_value' => ['name' => 'Subsite', 'code' => '654321']];
        $result = MailService::sendEmail($params);
        $this->assertNull($result['error']);
        $this->assertSame(['mode' => 'html', 'body' => '<p>Subsite: 654321</p>', 'subject' => 'Subsite verification', 'from' => 'Subsite'], $transport->calls[0]);
        $this->assertSame('Subsite verification', MailLog::query()->first()->subject);
        $templates->save('default', 'verify', null);
        $result = MailService::sendEmail($params);
        $this->assertNull($result['error']);
        $this->assertSame('view', $transport->calls[1]['mode']);
        $this->assertSame('mail.default.verify', $transport->calls[1]['body']);
        $this->assertSame('Original subject', $transport->calls[1]['subject']);
    }

    public function test_every_new_theme_resolves_through_the_actual_mail_delivery_view_path(): void
    {
        $transport = new class {
            public array $calls = [];
            public function send($view, $values, $callback): void {
                $email = new Email(); $callback(new Message($email));
                $this->calls[] = ['view' => $view, 'html' => view($view, $values)->render(), 'subject' => $email->getSubject()];
            }
        };
        Mail::swap($transport);
        $templates = new EmailTemplateService();
        foreach (['breeze', 'letter', 'coral', 'midnight'] as $template) {
            admin_setting(['email_template' => $template]);
            foreach (array_keys(EmailTemplateService::SUBJECTS) as $kind) {
                $values = $templates->samples($kind);
                $result = MailService::sendEmail(['email' => 'sample@example.test', 'subject' => 'Original subject', 'template_name' => $kind, 'template_value' => $values]);
                $this->assertNull($result['error']);
                $call = end($transport->calls);
                $this->assertSame('mail.' . $template . '.' . $kind, $call['view']);
                $this->assertSame($templates->preview($template, $kind)['html'], $call['html']);
                $this->assertSame('Original subject', $call['subject']);
            }
        }
        $this->assertCount(20, $transport->calls);
    }
}
