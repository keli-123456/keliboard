<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OfficialKnowledgePackContentTest extends TestCase
{
    private static function directory(): string
    {
        return dirname(__DIR__, 3) . '/database/knowledge-packs/client-guides';
    }

    private static function manifest(): array
    {
        return json_decode(file_get_contents(self::directory() . '/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    private static function body(string $slug): string
    {
        return file_get_contents(self::directory() . '/articles/' . $slug . '.html');
    }

    public static function articles(): iterable
    {
        foreach (self::manifest()['articles'] as $article) {
            yield $article['slug'] => [$article];
        }
    }

    public function test_manifest_has_unique_stable_entries_and_the_new_guides(): void
    {
        $manifest = self::manifest();
        $this->assertSame('2026.11.01', $manifest['version']);
        $this->assertCount(18, $manifest['articles']);
        foreach (['slug', 'sort', 'body'] as $field) {
            $values = array_column($manifest['articles'], $field);
            $this->assertCount(count($values), array_unique($values), $field);
        }
        $slugs = array_column($manifest['articles'], 'slug');
        foreach (['client-compatibility', 'hysteria2-ech', 'trusttunnel',
            'connection-troubleshooting', 'site-navigation', 'commission-agent'] as $slug) {
            $this->assertContains($slug, $slugs);
        }
    }

    #[DataProvider('articles')]
    public function test_article_is_safe_html_with_known_and_protected_placeholders(array $article): void
    {
        $directory = realpath(self::directory());
        $path = realpath(self::directory() . '/' . $article['body']);
        $this->assertNotFalse($path);
        $this->assertStringStartsWith($directory . DIRECTORY_SEPARATOR, $path);
        $body = file_get_contents($path);
        $this->assertMatchesRegularExpression('/^<h2>.+<\/h2>/u', $body);
        $this->assertDoesNotMatchRegularExpression('/<\/?(?:script|iframe|style|img|form|input)\b|\son[a-z]+\s*=|\sstyle\s*=/i', $body);
        $this->assertDoesNotMatchRegularExpression('/!\[[^\]]*\]\(/', $body);
        $this->assertSame([], $article['assets']);
        $this->assertTrue($article['show']);
        $this->assertSame('global', $article['scope_type'] ?? 'global');

        preg_match_all('/\{\{([^}]+)\}\}/', $body, $matches);
        $this->assertSame([], array_values(array_diff($matches[1], [
            'siteName', 'siteUrl', 'subscribeUrl', 'supportName', 'supportUrl', 'telegramUrl',
        ])));
        if ($article['requires_subscription_url'] ?? true) {
            $this->assertSame(1, substr_count($body, '<!--access start-->'));
            $this->assertSame(1, substr_count($body, '<!--access end-->'));
            $this->assertMatchesRegularExpression('/<!--access start-->.*\{\{subscribeUrl\}\}.*<!--access end-->/s', $body);
            $unprotected = preg_replace('/<!--access start-->.*?<!--access end-->/s', '', $body);
            $this->assertStringNotContainsString('{{subscribeUrl}}', $unprotected);
        } else {
            $this->assertStringNotContainsString('{{subscribeUrl}}', $body);
        }

        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $this->assertTrue($document->loadHTML('<?xml encoding="UTF-8"><html><body>' . $body . '</body></html>', LIBXML_NONET));
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $xpath = new DOMXPath($document);
        foreach ($xpath->query('//*[@href]') as $link) {
            $href = $link->getAttribute('href');
            if (preg_match('/^\{\{(?:siteUrl|supportUrl|telegramUrl)\}\}$/D', $href)) {
                continue;
            }
            $this->assertSame('https', parse_url($href, PHP_URL_SCHEME));
            $this->assertNull(parse_url($href, PHP_URL_USER));
            $this->assertNull(parse_url($href, PHP_URL_PASS));
        }
        foreach ($xpath->query('//table') as $table) {
            $this->assertCount(3, $xpath->query('./thead/tr/th', $table));
            foreach ($xpath->query('./tbody/tr', $table) as $row) {
                $this->assertCount(3, $xpath->query('./td', $row));
            }
        }
    }

    public function test_documented_core_floors_match_the_panels_export_policy(): void
    {
        $config = require dirname(__DIR__, 3) . '/config/protocol_capabilities.php';
        $body = self::body('client-compatibility');
        foreach ($config['hy2_ech_clients'] as $version) {
            $this->assertStringContainsString($version, $body);
        }
        $floor = $config['clients']['mihomo']['supports']['trusttunnel'][0]['min_version'];
        $this->assertStringContainsString($floor, $body);
        $this->assertStringContainsString($floor, self::body('trusttunnel'));
        $this->assertStringContainsString('保守导出门槛', $body);
        $this->assertStringContainsString('不是所有软件的首个支持版本', $body);
    }

    public function test_security_and_scope_boundaries_remain_explicit(): void
    {
        $ech = self::body('hysteria2-ech');
        $this->assertStringContainsString('并不强制所有连接使用它', $ech);
        $this->assertStringContainsString('不是用户人数', $ech);
        $this->assertStringContainsString('不能隐藏服务器 IP', $ech);
        $trustTunnel = self::body('trusttunnel');
        $this->assertStringContainsString('skip-cert-verify: false', $trustTunnel);
        $this->assertStringNotContainsString('skip-cert-verify: true', $trustTunnel);
        $this->assertStringContainsString('client-fingerprint', $trustTunnel);
        $this->assertStringContainsString('不表示客户端一定会自动切换成 H2', $trustTunnel);
        $this->assertStringContainsString('自签加 ECH', $trustTunnel);
        $commission = self::body('commission-agent');
        $this->assertStringContainsString('Polygon PoS (POL)', $commission);
        $this->assertStringContainsString('只能选择页面实际开放的网络', $commission);
        $this->assertStringContainsString('不要只根据地址前缀判断网络', $commission);
        $this->assertStringContainsString('不等于已经链上转账', $commission);
        $this->assertStringNotContainsString('50%', $commission);
        $this->assertStringContainsString('不是保证最快的自动测速排名', self::body('site-navigation'));
    }
}
