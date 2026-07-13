<?php

namespace App\Services;

use Illuminate\Support\Arr;

class TicketAiContentSanitizer
{
    public function sanitize(string $value, int $maxLength = 2000): string
    {
        $value = preg_replace(
            '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/iu',
            '[EMAIL]',
            $value
        ) ?? $value;
        $value = preg_replace(
            '/\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\b/iu',
            '[UUID]',
            $value
        ) ?? $value;
        $value = preg_replace(
            '/\bBearer\s+[A-Za-z0-9._~+\/=\-]+/iu',
            'Bearer [TOKEN]',
            $value
        ) ?? $value;
        $value = preg_replace(
            '/\bsk-[A-Za-z0-9_\-]{8,}\b/u',
            '[TOKEN]',
            $value
        ) ?? $value;
        $value = preg_replace(
            '/(password|passwd|pwd|密码)\s*[:=：]\s*["\']?[^\s"\']+["\']?/iu',
            '$1=[REDACTED]',
            $value
        ) ?? $value;
        $value = preg_replace(
            '/\b(token|api[\s_\-]?key|secret)\s*[:=：]\s*["\']?[A-Za-z0-9._~+\/=\-]{8,}["\']?/iu',
            '$1=[TOKEN]',
            $value
        ) ?? $value;
        $value = preg_replace(
            '#(?<=/)[A-Za-z0-9_\-]{24,}(?=[/?\#\s]|$)#u',
            '[TOKEN]',
            $value
        ) ?? $value;

        return mb_substr($value, 0, max(0, $maxLength));
    }

    /**
     * @return array<int, array{role:string, content:string}>
     */
    public function sanitizeConversation(
        iterable $messages,
        int $maxMessages,
        int $maxTotalChars = 12000
    ): array {
        $normalized = [];
        foreach ($messages as $message) {
            $role = (string) $this->value($message, 'role', 'user');
            if (!in_array($role, ['system', 'user', 'assistant'], true)) {
                $role = 'user';
            }

            $normalized[] = [
                'role' => $role,
                'content' => (string) $this->value(
                    $message,
                    'content',
                    $this->value($message, 'message', '')
                ),
            ];
        }

        $retained = array_slice($normalized, -max(0, $maxMessages));
        $remaining = max(0, $maxTotalChars);
        $result = [];
        $count = count($retained);

        foreach ($retained as $index => $message) {
            $remainingMessages = max(1, $count - $index);
            $budget = intdiv($remaining, $remainingMessages);
            $content = $this->sanitize($message['content'], $budget);
            $remaining -= mb_strlen($content);
            $result[] = ['role' => $message['role'], 'content' => $content];
        }

        return $result;
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>>
     */
    public function sanitizeKnowledge(array $items, int $maxTotalChars = 6000): array
    {
        $remaining = max(0, $maxTotalChars);
        $result = [];

        foreach ($items as $item) {
            if ($remaining <= 0) {
                break;
            }

            $fields = ['title', 'category', 'body'];
            $sanitized = Arr::except($item, $fields);
            foreach ($fields as $index => $field) {
                $remainingFields = max(1, count($fields) - $index);
                $budget = intdiv($remaining, $remainingFields);
                $sanitized[$field] = $this->sanitize((string) ($item[$field] ?? ''), $budget);
                $remaining -= mb_strlen($sanitized[$field]);
            }
            $result[] = $sanitized;
        }

        return $result;
    }

    private function value(mixed $source, string $key, mixed $default = null): mixed
    {
        if (is_array($source)) {
            return $source[$key] ?? $default;
        }

        if (is_object($source)) {
            return $source->{$key} ?? $default;
        }

        return $default;
    }
}
