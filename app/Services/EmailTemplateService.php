<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

class EmailTemplateService
{
    public const SUBJECTS = [
        'remindExpire' => '服务到期提醒', 'remindTraffic' => '流量使用提醒',
        'verify' => '邮箱验证码', 'mailLogin' => '邮箱登录', 'notify' => '系统通知',
    ];

    public function file(string $template, string $kind): string
    {
        abort_unless(preg_match('/\A[a-zA-Z0-9_-]{1,80}\z/', $template) && isset(self::SUBJECTS[$kind]), 404);
        $root = realpath(resource_path('views/mail'));
        $directory = $root ? realpath($root . DIRECTORY_SEPARATOR . $template) : false;
        $file = $directory ? realpath($directory . DIRECTORY_SEPARATOR . $kind . '.blade.php') : false;
        abort_unless($root && $directory && dirname($directory) === $root && $file && dirname($file) === $directory && is_file($file), 404, '邮件模板不存在或不支持此邮件类型');
        return $file;
    }

    public function samples(string $kind): array
    {
        return [
            'name' => (string) admin_setting('app_name', 'XBoard'), 'url' => 'https://preview.example.invalid',
            'link' => 'https://preview.example.invalid/#/login?sample=1', 'code' => '123456',
            'content' => "这是一封邮件模板预览。\n邮件中的用户信息、验证码与链接均为示例，不会实际发送。",
            'subject' => self::SUBJECTS[$kind],
        ];
    }

    private function key(string $template, string $kind): string
    {
        return 'email_template_override_' . hash('sha256', $template . '/' . $kind);
    }

    public function override(string $template, string $kind): ?array
    {
        $value = admin_setting($this->key($template, $kind));
        return is_array($value) && is_string($value['subject'] ?? null) && is_string($value['html'] ?? null) ? $value : null;
    }

    public function editor(string $template, string $kind): array
    {
        $file = $this->file($template, $kind);
        // Only the installed view is compiled. User-supplied HTML is never passed to Blade.
        $tokens = array_combine(['name', 'url', 'link', 'code', 'content'], ['{{name}}', '{{url}}', '{{link}}', '{{code}}', '{{content}}']);
        $defaults = ['subject' => '{{subject}}', 'html' => view()->file($file, $tokens)->render()];
        $override = $this->override($template, $kind);
        $current = $override ?? $defaults;
        return [
            'template' => $template, 'kind' => $kind, 'customized' => $override !== null,
            'subject' => $current['subject'], 'html' => $current['html'], 'defaults' => $defaults,
            'preview' => $this->preview($template, $kind),
        ];
    }

    public function validateDocument(string $kind, array $document): void
    {
        validator($document, [
            'subject' => ['required', 'string', 'max:200', 'not_regex:/[\r\n]/'],
            'html' => ['required', 'string', 'max:100000'],
        ])->validate();
        $html = $document['html'];
        $allowed = ['name', 'url', 'subject', ...match ($kind) { 'verify' => ['code'], 'mailLogin' => ['link'], 'notify' => ['content'], default => [] }];
        foreach (['subject', 'html'] as $field) {
            $value = $document[$field];
            if (str_contains($value, '<?') || str_contains($value, '{!!') || preg_match('/@(?:php|include|extends|yield|inject|component)\b/i', $value)) {
                throw ValidationException::withMessages([$field => '不支持 PHP 或 Blade 指令，请使用 HTML 和支持的变量。']);
            }
            $remaining = preg_replace_callback('/\{\{\s*([a-zA-Z_]+)\s*\}\}/', function ($match) use ($allowed, $field) {
                if (!in_array($match[1], $allowed, true)) throw ValidationException::withMessages([$field => '此邮件类型不支持变量：' . $match[1]]);
                return '';
            }, $value);
            if (str_contains($remaining, '{{') || str_contains($remaining, '}}')) {
                throw ValidationException::withMessages([$field => '模板变量格式不正确。']);
            }
        }
        $required = ['verify' => 'code', 'mailLogin' => 'link', 'notify' => 'content'][$kind] ?? null;
        if ($required && !preg_match('/\{\{\s*' . $required . '\s*\}\}/', $html)) {
            throw ValidationException::withMessages(['html' => '正文必须保留变量 {{' . $required . '}}。']);
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $dom = new \DOMDocument();
            $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
            foreach ($dom->getElementsByTagName('*') as $element) {
                $tag = strtolower($element->tagName);
                if (in_array($tag, ['script', 'iframe', 'frame', 'frameset', 'object', 'embed', 'form', 'input', 'button', 'base'], true)
                    || ($tag === 'meta' && strtolower($element->getAttribute('http-equiv')) === 'refresh')) {
                    throw ValidationException::withMessages(['html' => '邮件正文不能包含脚本、表单或嵌入页面。']);
                }
                foreach ($element->attributes as $attribute) {
                    $url = preg_replace('/[\x00-\x20\x7f]+/', '', $attribute->value);
                    if (str_starts_with(strtolower($attribute->name), 'on') || (in_array(strtolower($attribute->name), ['href', 'src', 'xlink:href'], true) && preg_match('/^(?:(?:javascript|vbscript):|data:text\/html)/i', $url))) {
                        throw ValidationException::withMessages(['html' => '邮件正文包含不安全的属性。']);
                    }
                }
            }
        } finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
    }

    public function render(array $document, array $values): array
    {
        $replace = function (string $source, bool $html) use ($values): string {
            return preg_replace_callback('/\{\{\s*([a-zA-Z_]+)\s*\}\}/', static function ($match) use ($values, $html) {
                $value = (string) ($values[$match[1]] ?? '');
                // Notification content already carries HTML in the existing delivery contract.
                if ($html && $match[1] === 'content') return nl2br($value);
                return $html ? e($value) : str_replace(["\r", "\n"], '', $value);
            }, $source);
        };
        return ['subject' => $replace($document['subject'], false), 'html' => $replace($document['html'], true)];
    }

    public function preview(string $template, string $kind, ?array $draft = null): array
    {
        $file = $this->file($template, $kind);
        $document = $draft ?? $this->override($template, $kind);
        if ($draft !== null) $this->validateDocument($kind, $draft);
        $values = $this->samples($kind);
        $rendered = $document ? $this->render($document, $values) : ['subject' => $values['subject'], 'html' => view()->file($file, $values)->render()];
        return ['template' => $template, 'kind' => $kind, ...$rendered];
    }

    public function save(string $template, string $kind, ?array $document): array
    {
        $this->file($template, $kind);
        if ($document !== null) $this->validateDocument($kind, $document);
        // One key per email avoids overwriting edits to a different type or theme.
        admin_setting([$this->key($template, $kind) => $document]);
        return $this->editor($template, $kind);
    }
}
