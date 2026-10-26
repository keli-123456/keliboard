<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use Illuminate\View\ViewServiceProvider;
use Tests\TestCase;

final class SiteNavigationViewTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['view.paths' => [resource_path('views')], 'view.compiled' => storage_path('framework/views')]);
        app()->register(ViewServiceProvider::class);
    }

    public function test_recommendation_and_proxy_keep_their_links_and_distinct_groups(): void
    {
        $html = $this->render();
        $xpath = $this->xpath($html);
        $this->assertSame(1, $xpath->query('//a[contains(@class,"entry-primary")]')->length);
        $this->assertSame(1, $xpath->query('//a[contains(@class,"entry-secondary")]')->length);
        $this->assertSame('https://main.example.test', $xpath->query('//a')->item(0)->getAttribute('href'));
        $this->assertSame('https://203.0.113.10:8444', $xpath->query('//a')->item(1)->getAttribute('href'));
        $this->assertStringContainsString('网站反代', $html);
        $this->assertStringContainsString('2 个地址', $html);
        $this->assertSame(0, $xpath->query('//a//a | //a//button')->length);
        $this->assertSame(1, substr_count($html, 'Lucide icons: ISC License'));
    }

    public function test_manual_only_entries_do_not_become_an_automatic_recommendation(): void
    {
        $html = $this->render(['destinations' => [
            ['label' => '帮助中心', 'url' => 'https://help.example.test', 'kind' => 'manual', 'recommended' => false],
        ]]);
        $xpath = $this->xpath($html);
        $this->assertSame(0, $xpath->query('//a[contains(@class,"entry-primary")]')->length);
        $this->assertSame(1, $xpath->query('//a')->length);
        $this->assertStringContainsString('快捷入口', $html);
    }

    public function test_empty_and_logo_fallback_states_render_without_scripts_or_external_styles(): void
    {
        $html = $this->render(['destinations' => [], 'logo_url' => null, 'updated_at' => null]);
        $xpath = $this->xpath($html);
        $this->assertStringContainsString('暂时没有可用地址', $html);
        $this->assertStringContainsString('0 个地址', $html);
        $this->assertSame(0, $xpath->query('//a | //img | //script | //link | //form | //iframe')->length);
        $this->assertSame('F', $xpath->query('//span[contains(@class,"monogram")]')->item(0)->textContent);
        $this->assertSame(0, $xpath->query('//span[contains(@class,"updated")]')->length);
    }

    public function test_custom_content_and_address_attributes_are_escaped(): void
    {
        $html = $this->render([
            'title' => '<script>alert(1)</script>', 'description' => '<img src=x onerror=alert(1)>',
            'announcement' => '<b>Notice</b>', 'logo_url' => 'https://img.example.test/logo.png" onerror="alert(1)',
            'destinations' => [['label' => '<svg onload=alert(1)>', 'url' => 'https://safe.example.test/?q=" onclick="bad', 'kind' => 'manual', 'recommended' => false]],
        ]);
        $xpath = $this->xpath($html);
        $this->assertSame(0, $xpath->query('//script | //*[@onerror] | //*[@onclick] | //*[@onload]')->length);
        $this->assertSame('<script>alert(1)</script>', $xpath->query('//h1')->item(0)->textContent);
        $this->assertStringContainsString('&lt;b&gt;Notice&lt;/b&gt;', $html);
        $this->assertSame('https://safe.example.test/?q=" onclick="bad', $xpath->query('//a')->item(0)->getAttribute('href'));
    }

    public function test_opportunities_and_help_follow_addresses_without_nested_controls_or_scripts(): void
    {
        $html = $this->render([
            'opportunities' => [[
                'kind' => 'referral', 'title' => '邀请返利', 'description' => '分享邀请链接',
                'detail' => '以账户规则为准', 'action' => '查看返利规则', 'url' => 'https://main.example.test/#/referral-center',
            ], [
                'kind' => 'agent', 'title' => '代理合作', 'description' => '管理下级账户',
                'detail' => '以开通条件为准', 'action' => '了解代理合作', 'url' => 'https://main.example.test/#/agent-center',
            ]],
            'help_links' => [['label' => '联系客服 <官方>', 'url' => 'https://support.example.test']],
        ]);
        $xpath = $this->xpath($html);
        $this->assertSame(2, $xpath->query('//section[@class="opportunities"]//a')->length);
        $this->assertSame('https://main.example.test/#/agent-center', $xpath->query('//section[@class="opportunities"]//a')->item(1)->getAttribute('href'));
        $this->assertSame('联系客服 <官方>', $xpath->query('//nav[@class="help"]//a')->item(0)->textContent);
        $this->assertLessThan(strpos($html, '<section class="opportunities"'), strpos($html, '<section class="secondary"'));
        $this->assertLessThan(strpos($html, '<nav class="help"'), strpos($html, '<section class="opportunities"'));
        $this->assertSame(0, $xpath->query('//a//a | //a//button | //script | //form | //iframe')->length);
        $this->assertSame(1, substr_count($html, 'Lucide icons: ISC License'));
    }

    public function test_missing_optional_information_adds_no_empty_sections(): void
    {
        $xpath = $this->xpath($this->render(['opportunities' => [], 'help_links' => []]));
        $this->assertSame(0, $xpath->query('//section[@class="opportunities"] | //nav[@class="help"]')->length);
    }

    private function render(array $extra = []): string
    {
        return view('site_navigation', array_replace([
            'title' => 'FSCloud', 'description' => '请选择可用地址继续访问', 'announcement' => '',
            'logo_url' => 'https://img.example.test/logo.png', 'updated_at' => 1792994202,
            'destinations' => [
                ['label' => '推荐入口', 'url' => 'https://main.example.test', 'kind' => 'site', 'recommended' => true],
                ['label' => '备用访问 1', 'url' => 'https://203.0.113.10:8444', 'kind' => 'proxy', 'recommended' => false],
            ],
        ], $extra))->render();
    }

    private function xpath(string $html): \DOMXPath
    {
        $old = libxml_use_internal_errors(true);
        $document = new \DOMDocument();
        $document->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($old);
        return new \DOMXPath($document);
    }
}
