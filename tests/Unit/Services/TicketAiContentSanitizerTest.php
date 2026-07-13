<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\TicketAiContentSanitizer;
use PHPUnit\Framework\TestCase;

final class TicketAiContentSanitizerTest extends TestCase
{
    public function test_sensitive_identifiers_are_redacted_without_removing_normal_help_urls(): void
    {
        $input = implode(' ', [
            'mail a@example.com',
            'uuid 123e4567-e89b-12d3-a456-426614174000',
            'Authorization: Bearer eyJhbGciOiJIUzI1NiJ9.secret.signature',
            'password=secret123',
            'token=0123456789abcdef0123456789abcdef',
            'https://panel.example.com/sub/demo/abcdef0123456789abcdef0123456789',
            'https://help.example.com/a',
        ]);

        $output = (new TicketAiContentSanitizer())->sanitize($input, 1000);

        $this->assertStringNotContainsString('a@example.com', $output);
        $this->assertStringNotContainsString('123e4567-e89b-12d3-a456-426614174000', $output);
        $this->assertStringNotContainsString('eyJhbGciOiJIUzI1NiJ9.secret.signature', $output);
        $this->assertStringNotContainsString('secret123', $output);
        $this->assertStringNotContainsString('0123456789abcdef0123456789abcdef', $output);
        $this->assertStringContainsString('[EMAIL]', $output);
        $this->assertStringContainsString('[UUID]', $output);
        $this->assertStringContainsString('https://help.example.com/a', $output);
    }

    public function test_chinese_secret_separator_and_uuid_v7_are_redacted(): void
    {
        $input = implode(' ', [
            '我的密码：secret123',
            'api key：abcdef0123456789',
            'uuid 018f22e2-7a9b-7cc2-98c4-dc0c0c07398f',
        ]);

        $output = (new TicketAiContentSanitizer())->sanitize($input, 1000);

        $this->assertStringNotContainsString('secret123', $output);
        $this->assertStringNotContainsString('abcdef0123456789', $output);
        $this->assertStringNotContainsString('018f22e2-7a9b-7cc2-98c4-dc0c0c07398f', $output);
    }

    public function test_sanitize_respects_a_unicode_character_limit(): void
    {
        $output = (new TicketAiContentSanitizer())->sanitize(str_repeat('测试内容', 20), 17);

        $this->assertSame(17, mb_strlen($output));
    }

    public function test_conversation_keeps_latest_messages_in_chronological_order_and_total_bound(): void
    {
        $messages = [
            ['role' => 'user', 'content' => 'first a@example.com'],
            ['role' => 'assistant', 'content' => 'second message'],
            ['role' => 'user', 'content' => 'third message with password=secret'],
            ['role' => 'assistant', 'content' => 'fourth message'],
        ];

        $result = (new TicketAiContentSanitizer())->sanitizeConversation($messages, 3, 30);

        $this->assertCount(3, $result);
        $this->assertSame('assistant', $result[0]['role']);
        $this->assertStringStartsWith('second', $result[0]['content']);
        $this->assertSame('assistant', $result[2]['role']);
        $this->assertLessThanOrEqual(30, array_sum(array_map(
            static fn (array $message): int => mb_strlen($message['content']),
            $result
        )));
        $this->assertStringNotContainsString('secret', implode(' ', array_column($result, 'content')));
    }

    public function test_knowledge_items_are_sanitized_and_bounded(): void
    {
        $items = [
            ['id' => 1, 'title' => '账号 a@example.com', 'category' => '登录', 'body' => str_repeat('A', 40)],
            ['id' => 2, 'title' => '第二篇', 'category' => '订阅', 'body' => str_repeat('B', 40)],
        ];

        $result = (new TicketAiContentSanitizer())->sanitizeKnowledge($items, 45);
        $serialized = implode('', array_map(
            static fn (array $item): string => $item['title'] . $item['category'] . $item['body'],
            $result
        ));

        $this->assertNotEmpty($result);
        $this->assertStringNotContainsString('a@example.com', $serialized);
        $this->assertLessThanOrEqual(45, mb_strlen($serialized));
    }
}
