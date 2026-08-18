<?php

namespace App\Services;

use App\Exceptions\TicketAiProviderException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class TicketAiProviderClient
{
    /**
     * @param array<string, mixed> $settings
     * @param array<int, array{role:string, content:string}> $messages
     * @return array<string, mixed>
     */
    public function complete(array $settings, array $messages, bool $validateStructuredContent = true): array
    {
        $baseUrl = rtrim(trim((string) ($settings['base_url'] ?? '')), '/');
        $model = trim((string) ($settings['model'] ?? ''));
        $apiKey = trim((string) ($settings['api_key'] ?? ''));
        if ($baseUrl === '' || $model === '') {
            throw new TicketAiProviderException('connection');
        }
        if ($apiKey === '') {
            throw new TicketAiProviderException('authentication');
        }
        $safetyReason = $this->endpointSafetyReason($baseUrl, (bool) ($settings['allow_private_provider'] ?? false));
        if ($safetyReason !== null) {
            throw new TicketAiProviderException($safetyReason);
        }

        $timeout = max(5, min(120, (int) ($settings['timeout'] ?? 30)));
        $maxTokens = max(128, min(4096, (int) ($settings['max_tokens'] ?? 800)));
        $payload = [
            'model' => $model,
            'messages' => $messages,
        ];
        if ($this->usesReasoningModelParameters($model)) {
            $payload['max_completion_tokens'] = $maxTokens;
            if ($this->isOfficialOpenAiEndpoint($baseUrl)) {
                $payload['reasoning_effort'] = 'low';
            }
        } else {
            $payload['temperature'] = max(0.0, min(1.0, (float) ($settings['temperature'] ?? 0.2)));
            $payload['max_tokens'] = $maxTokens;
        }
        if ((bool) ($settings['json_mode'] ?? false)) {
            $payload['response_format'] = ['type' => 'json_object'];
        }

        $startedAt = hrtime(true);
        try {
            $response = Http::acceptJson()
                ->withToken($apiKey)
                ->withOptions(['allow_redirects' => false])
                ->timeout($timeout)
                ->post($this->endpoint($baseUrl), $payload);
        } catch (ConnectionException $exception) {
            $message = strtolower($exception->getMessage());
            $code = str_contains($message, 'timed out') || str_contains($message, 'timeout') || str_contains($message, 'error 28')
                ? 'timeout'
                : 'connection';

            throw new TicketAiProviderException($code);
        } catch (\Throwable) {
            throw new TicketAiProviderException('connection');
        }
        $latencyMs = (int) round((hrtime(true) - $startedAt) / 1_000_000);

        if (!$response->successful()) {
            throw new TicketAiProviderException($this->httpErrorCode($response->status()));
        }

        $responsePayload = $response->json();
        $content = is_array($responsePayload) ? $this->extractContent($responsePayload) : null;
        if ($content === null || trim($content) === '') {
            throw new TicketAiProviderException('invalid_response');
        }
        $content = trim($content);
        $decoded = $validateStructuredContent ? $this->decodeStructuredContent($content) : null;
        $inputTokens = max(0, (int) (data_get($responsePayload, 'usage.prompt_tokens')
            ?? data_get($responsePayload, 'usage.input_tokens', 0)));
        $outputTokens = max(0, (int) (data_get($responsePayload, 'usage.completion_tokens')
            ?? data_get($responsePayload, 'usage.output_tokens', 0)));
        $reportedTotal = max(0, (int) data_get($responsePayload, 'usage.total_tokens', 0));

        return [
            'content' => $content,
            'decoded' => $decoded,
            'structured' => $decoded !== null,
            'latency_ms' => max(0, $latencyMs),
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'total_tokens' => $reportedTotal > 0 ? $reportedTotal : $inputTokens + $outputTokens,
            'prompt_chars' => array_sum(array_map(
                static fn (array $message): int => mb_strlen((string) ($message['content'] ?? '')),
                $messages
            )),
            'response_chars' => mb_strlen($content),
        ];
    }

    /** @param array<string, mixed> $payload */
    private function extractContent(array $payload): ?string
    {
        foreach ([
            data_get($payload, 'choices.0.message.content'),
            data_get($payload, 'choices.0.text'),
            data_get($payload, 'output_text'),
            data_get($payload, 'output'),
        ] as $candidate) {
            $content = $this->contentValue($candidate);
            if ($content !== null && trim($content) !== '') {
                return $content;
            }
        }

        return null;
    }

    private function contentValue(mixed $value): ?string
    {
        if (is_string($value)) {
            return $value;
        }
        if (!is_array($value)) {
            return null;
        }

        if (array_is_list($value)) {
            $parts = [];
            foreach ($value as $item) {
                $part = $this->contentValue($item);
                if ($part !== null && $part !== '') {
                    $parts[] = $part;
                }
            }

            return $parts === [] ? null : implode('', $parts);
        }

        foreach (['text', 'output_text', 'content', 'value'] as $key) {
            if (!array_key_exists($key, $value)) {
                continue;
            }
            $content = $this->contentValue($value[$key]);
            if ($content !== null && trim($content) !== '') {
                return $content;
            }
        }

        return null;
    }

    public function endpointSafetyReason(string $baseUrl, bool $allowPrivate = false): ?string
    {
        $parts = parse_url(trim($baseUrl));
        $scheme = strtolower((string) (is_array($parts) ? ($parts['scheme'] ?? '') : ''));
        $host = (string) (is_array($parts) ? ($parts['host'] ?? '') : '');
        if (
            !in_array($scheme, ['http', 'https'], true)
            || $host === ''
            || (is_array($parts) && (isset($parts['user']) || isset($parts['pass'])))
        ) {
            return 'unsafe_endpoint';
        }

        $host = strtolower(trim($host, '[]'));
        if (in_array($host, ['169.254.169.254', 'metadata.google.internal', 'metadata.azure.internal'], true)) {
            return 'unsafe_endpoint';
        }

        if ($allowPrivate) {
            return null;
        }

        if ($host === 'localhost' || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            return 'unsafe_endpoint';
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return $this->isPublicIp($host) ? null : 'unsafe_endpoint';
        }

        $resolved = gethostbynamel($host) ?: [];
        foreach ($resolved as $ip) {
            if (!$this->isPublicIp((string) $ip)) {
                return 'unsafe_endpoint';
            }
        }

        return null;
    }

    private function isPublicIp(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }
    private function endpoint(string $baseUrl): string
    {
        return str_ends_with($baseUrl, '/chat/completions')
            ? $baseUrl
            : $baseUrl . '/chat/completions';
    }

    private function usesReasoningModelParameters(string $model): bool
    {
        return preg_match('/^(?:gpt-5(?:[.-]|$)|o[134](?:[.-]|$))/i', trim($model)) === 1;
    }

    private function isOfficialOpenAiEndpoint(string $baseUrl): bool
    {
        $host = parse_url(trim($baseUrl), PHP_URL_HOST);

        return is_string($host) && strtolower($host) === 'api.openai.com';
    }

    private function httpErrorCode(int $status): string
    {
        return match (true) {
            in_array($status, [401, 403], true) => 'authentication',
            $status === 408 => 'timeout',
            $status === 429 => 'rate_limited',
            default => 'upstream',
        };
    }

    /** @return array<string, mixed>|null */
    private function decodeStructuredContent(string $content): ?array
    {
        $decoded = $this->decodeJsonObject($content);
        if ($decoded !== null) {
            return $decoded;
        }

        if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/isu', $content, $matches) === 1) {
            $decoded = $this->decodeJsonObject((string) $matches[1]);
            if ($decoded !== null) {
                return $decoded;
            }
        }

        $candidate = $this->firstBalancedObject($content);

        return $candidate === null ? null : $this->decodeJsonObject($candidate);
    }

    /** @return array<string, mixed>|null */
    private function decodeJsonObject(string $value, int $depth = 0): ?array
    {
        if ($depth > 3) {
            return null;
        }

        $decoded = json_decode(trim($value), true);
        if (is_string($decoded)) {
            return $this->decodeJsonObject($decoded, $depth + 1);
        }

        if (!is_array($decoded) || array_is_list($decoded)) {
            return null;
        }

        foreach (['result', 'data', 'response', 'output'] as $wrapper) {
            $candidate = $decoded[$wrapper] ?? null;
            if (is_string($candidate)) {
                $unwrapped = $this->decodeJsonObject($candidate, $depth + 1);
                if ($unwrapped !== null) {
                    return $unwrapped;
                }
            }
            if (is_array($candidate) && !array_is_list($candidate) && $this->isValidStructuredPayload($candidate)) {
                return $candidate;
            }
        }

        $decoded = $this->normalizeStructuredPayload($decoded);
        if ($this->isValidStructuredPayload($decoded)) {
            return $decoded;
        }

        throw new TicketAiProviderException('invalid_response');
    }

    /** @param array<string, mixed> $decoded @return array<string, mixed> */
    private function normalizeStructuredPayload(array $decoded): array
    {
        $aliases = [
            'draft' => ['reply', 'reply_text', 'answer', 'final_answer', 'message', 'content', 'response', 'response_text', 'output', 'output_text', 'text', '回复草稿', '回复', '答复'],
            'summary' => ['摘要', '问题摘要'],
            'category' => ['分类'],
            'sentiment' => ['情绪'],
            'risk' => ['风险'],
            'needs_human' => ['需要人工', '转人工'],
            'confidence' => ['置信度'],
            'knowledge_refs' => ['知识库引用'],
        ];
        foreach ($aliases as $target => $sources) {
            if (array_key_exists($target, $decoded)) {
                continue;
            }
            foreach ($sources as $source) {
                if (array_key_exists($source, $decoded)) {
                    $decoded[$target] = $decoded[$source];
                    break;
                }
            }
        }

        if (array_key_exists('draft', $decoded) && !is_string($decoded['draft'])) {
            $draft = $this->contentValue($decoded['draft']);
            if ($draft !== null) {
                $decoded['draft'] = $draft;
            }
        }
        foreach (['summary', 'category', 'sentiment', 'risk'] as $field) {
            if (array_key_exists($field, $decoded) && $decoded[$field] === null) {
                unset($decoded[$field]);
            }
        }

        if (is_string($decoded['needs_human'] ?? null)) {
            $normalized = strtolower(trim((string) $decoded['needs_human']));
            if (in_array($normalized, ['true', '1', 'yes', '是', '需要'], true)) {
                $decoded['needs_human'] = true;
            } elseif (in_array($normalized, ['false', '0', 'no', '否', '不需要'], true)) {
                $decoded['needs_human'] = false;
            }
        }
        if (is_string($decoded['confidence'] ?? null) && is_numeric($decoded['confidence'])) {
            $decoded['confidence'] = (float) $decoded['confidence'];
        }
        if (array_key_exists('knowledge_refs', $decoded) && $decoded['knowledge_refs'] === null) {
            $decoded['knowledge_refs'] = [];
        }

        return $decoded;
    }

    /** @param array<string, mixed> $decoded */
    private function isValidStructuredPayload(array $decoded): bool
    {
        if (!is_string($decoded['draft'] ?? null) || trim($decoded['draft']) === '') {
            return false;
        }

        foreach (['summary', 'category', 'sentiment', 'risk'] as $field) {
            if (array_key_exists($field, $decoded) && !is_string($decoded[$field])) {
                return false;
            }
        }
        if (array_key_exists('needs_human', $decoded) && !is_bool($decoded['needs_human'])) {
            return false;
        }
        if (array_key_exists('confidence', $decoded) && !is_int($decoded['confidence']) && !is_float($decoded['confidence'])) {
            return false;
        }
        if (array_key_exists('knowledge_refs', $decoded)) {
            if (!is_array($decoded['knowledge_refs']) || !array_is_list($decoded['knowledge_refs'])) {
                return false;
            }
            foreach ($decoded['knowledge_refs'] as $reference) {
                if (!is_string($reference) && !is_int($reference)) {
                    return false;
                }
            }
        }

        return true;
    }

    private function firstBalancedObject(string $value): ?string
    {
        $length = strlen($value);
        for ($start = 0; $start < $length; $start++) {
            if ($value[$start] !== '{') {
                continue;
            }

            $depth = 0;
            $quoted = false;
            $escaped = false;
            for ($index = $start; $index < $length; $index++) {
                $character = $value[$index];
                if ($quoted) {
                    if ($escaped) {
                        $escaped = false;
                        continue;
                    }
                    if ($character === '\\') {
                        $escaped = true;
                        continue;
                    }
                    if ($character === '"') {
                        $quoted = false;
                    }
                    continue;
                }

                if ($character === '"') {
                    $quoted = true;
                } elseif ($character === '{') {
                    $depth++;
                } elseif ($character === '}') {
                    $depth--;
                    if ($depth === 0) {
                        return substr($value, $start, $index - $start + 1);
                    }
                }
            }
        }

        return null;
    }
}
