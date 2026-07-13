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
    public function complete(array $settings, array $messages): array
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

        $timeout = max(5, min(120, (int) ($settings['timeout'] ?? 30)));
        $maxTokens = max(128, min(4096, (int) ($settings['max_tokens'] ?? 800)));
        $payload = [
            'model' => $model,
            'temperature' => max(0.0, min(1.0, (float) ($settings['temperature'] ?? 0.2))),
            'max_tokens' => $maxTokens,
            'messages' => $messages,
        ];
        if ((bool) ($settings['json_mode'] ?? false)) {
            $payload['response_format'] = ['type' => 'json_object'];
        }

        $startedAt = hrtime(true);
        try {
            $response = Http::acceptJson()
                ->withToken($apiKey)
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

        $content = data_get($response->json(), 'choices.0.message.content');
        if (!is_string($content) || trim($content) === '') {
            throw new TicketAiProviderException('invalid_response');
        }
        $content = trim($content);
        $decoded = $this->decodeStructuredContent($content);
        $inputTokens = max(0, (int) data_get($response->json(), 'usage.prompt_tokens', 0));
        $outputTokens = max(0, (int) data_get($response->json(), 'usage.completion_tokens', 0));
        $reportedTotal = max(0, (int) data_get($response->json(), 'usage.total_tokens', 0));

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

    private function endpoint(string $baseUrl): string
    {
        return str_ends_with($baseUrl, '/chat/completions')
            ? $baseUrl
            : $baseUrl . '/chat/completions';
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
    private function decodeJsonObject(string $value): ?array
    {
        $decoded = json_decode(trim($value), true);

        if (!is_array($decoded) || array_is_list($decoded)) {
            return null;
        }
        if (!$this->isValidStructuredPayload($decoded)) {
            throw new TicketAiProviderException('invalid_response');
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
