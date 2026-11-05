<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\TicketAiOfficialLookup;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class TicketAiOfficialLookupTest extends TestCase
{
    public const WINDOWS = 'https://learn.microsoft.com/en-us/windows/win32/winsock/windows-sockets-error-codes-2';
    public const LINUX = 'https://man7.org/linux/man-pages/man2/bind.2.html';
    private const RELEASE = 'https://api.github.com/repos/2dust/v2rayN/releases/tags/7.25.6';

    public static function screenshotReplays(): array
    {
        $cases = TicketAiAutomationSendTest::anonymizedScreenshotCases();
        $replays = [];
        foreach ($cases as $label => [, $text]) {
            $replays[$label] = [$text, in_array(substr($label, 0, 2), ['01', '07', '08'], true)];
        }
        return $replays;
    }

    public static function portDocuments(): array
    {
        return [
            self::WINDOWS => Http::response('<table><tr><td>WSAEADDRINUSE</td><td>Address already in use. A local socket already uses this port.</td></tr></table>'),
            self::LINUX => Http::response('<pre>EADDRINUSE The given address is already in use.</pre>'),
        ];
    }

    #[DataProvider('screenshotReplays')]
    public function test_anonymized_replays_only_search_when_the_error_identifies_a_relevant_source(string $text, bool $match): void
    {
        Http::preventStrayRequests();
        Http::fake(self::portDocuments());
        $result = (new TicketAiOfficialLookup())->search($text);
        $this->assertSame($match ? [self::WINDOWS, self::LINUX] : [], array_column($result['sources'] ?? [], 'url'));
        Http::assertSentCount($match ? 2 : 0);
    }

    public function test_only_canonical_public_release_request_and_citation_are_used(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            self::RELEASE => Http::response(['tag_name' => '7.25.6', 'draft' => false, 'prerelease' => false,
                'html_url' => 'https://untrusted.test/not-a-source', 'body' => "- Fix Trojan on Windows\n- Unrelated change https://untrusted.test"]),
        ]);
        $lookup = new TicketAiOfficialLookup();
        $text = 'v2rayN 7.25.6 Trojan handshake error. https://private.test/sub?token=secret user@private.test';
        $result = $lookup->search($text);
        $this->assertSame(['https://github.com/2dust/v2rayN/releases/tag/7.25.6'], array_column($result['sources'], 'url'));
        $this->assertStringContainsString('Fix Trojan on Windows', $result['sources'][0]['excerpt']);
        $this->assertStringNotContainsString('untrusted', json_encode($result));
        $this->assertSame($result, $lookup->search($text));
        $reply = $lookup->controlledDraft($lookup->verifiedReply($result['sources']));
        $this->assertStringContainsString('7.25.6', $reply);
        $this->assertStringContainsString('不能确认', $reply);
        $this->assertStringNotContainsString('Fix Trojan', $reply);
        Http::assertSent(fn ($request) => $request->url() === self::RELEASE && $request->method() === 'GET'
            && $request->body() === '' && !$request->hasHeader('Authorization'));
        Http::assertSentCount(1);
    }

    public function test_redirect_mismatched_tag_and_private_url_are_not_sources(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            self::WINDOWS => Http::response('', 302, ['Location' => 'http://127.0.0.1/']),
            self::RELEASE => Http::response(['tag_name' => '7.25.7', 'draft' => false, 'prerelease' => false]),
        ]);
        $lookup = new TicketAiOfficialLookup();
        $this->assertSame(['sources' => []], $lookup->search('Windows local port is in use'));
        $this->assertSame(['sources' => []], $lookup->search('v2rayN 7.25.6 version compatibility'));
        $this->assertNull($lookup->search('https://private.test/v2rayN?version=7.25.6'));
        Http::assertSentCount(2);
    }

    public function test_explicit_mihomo_config_error_queries_only_the_publisher_docs(): void
    {
        $url = 'https://wiki.metacubex.one/en/handbook/syntax/';
        Http::preventStrayRequests();
        Http::fake([$url => Http::response('<main><p>yaml uses indentation. Tabs are not allowed.</p></main>')]);
        $lookup = new TicketAiOfficialLookup();
        $this->assertNull($lookup->search('Clash reports an invalid configuration.'));
        $this->assertSame([$url], array_column($lookup->search('Clash Meta reports an invalid configuration.')['sources'], 'url'));
        Http::assertSentCount(1);
    }

    public function test_generic_knowledge_does_not_mask_a_specific_error_but_matching_knowledge_does(): void
    {
        Http::preventStrayRequests();
        Http::fake(self::portDocuments());
        $lookup = new TicketAiOfficialLookup();
        $text = 'Windows local port is in use.';
        $this->assertNull($lookup->search($text, [['title' => 'Windows', 'body' => 'Address already in use.']]));
        $this->assertSame([self::WINDOWS], array_column($lookup->search($text, [['title' => 'Windows', 'body' => 'How to install the app.']])['sources'], 'url'));
        Http::assertSentCount(1);
    }

    public function test_latest_error_can_use_previously_named_client_without_searching_old_errors(): void
    {
        $url = 'https://wiki.metacubex.one/en/handbook/syntax/';
        Http::preventStrayRequests();
        Http::fake([$url => Http::response('<main><p>yaml uses indentation. Tabs are not allowed.</p></main>')]);
        $lookup = new TicketAiOfficialLookup();
        $this->assertNull($lookup->search('Thanks, it works now.', [], 'Using Mihomo, previous error address already in use.'));
        $this->assertNull($lookup->search('Clash reports an invalid configuration.', [], 'I previously used Mihomo.'));
        $this->assertSame([$url], array_column($lookup->search('The error says invalid configuration.', [], 'I am using Mihomo.')['sources'], 'url'));
        Http::assertSentCount(1);
    }
}
