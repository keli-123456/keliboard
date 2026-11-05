<?php

namespace App\Services;

use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Throwable;

class TicketAiOfficialLookup
{
    public const BUDGET_SECONDS = 6;
    public const REPLY_MODEL = 'system:official_client_support';
    public const GROUNDING = 'controlled_official_client_support';
    private const MAX_BYTES = 524288;
    private const REPLIES = [
        'port' => '如果报错出现在客户端启动本地代理时，这表示客户端无法绑定该本地地址或端口，不等于远端节点故障。请先退出重复运行的代理客户端再重试；如果端口仍被占用，可在客户端改用未占用的本地代理端口，并让系统代理指向新端口。请不要修改订阅里的节点端口。',
        'mihomo-config' => '如果 Mihomo 报配置解析失败，请先确认导入的是 Mihomo / Clash Meta 格式。若日志给出 YAML 行号，检查对应行是否用了制表符或缩进不齐：YAML 缩进应使用空格。同一层级应对齐。若仍报错，贴出错误原文和行号即可，请勿发送完整配置或订阅链接。',
        'sing-box-config' => '如果使用的是 sing-box，请先确认导入的是 sing-box 格式；本地配置应为 JSON。使用命令行客户端时，可以用官方的 sing-box check 校验本地配置。如果仍失败，请贴出校验错误原文和内核版本，不要发送完整配置或订阅链接。',
        'sing-box-tls' => '如果 sing-box 提示 TLS 握手或证书错误，请先校准设备时间，并确认客户端使用的 TLS 服务器名称与订阅配置一致。不要关闭证书校验。如果仍失败，请贴出完整的 TLS 错误文字和内核版本；仅凭握手失败不能判定是服务器还是客户端的问题。',
    ];

    private const PROJECTS = [
        'v2rayN' => ['v2rayN', '2dust/v2rayN', ''],
        'mihomo|clash[ -]meta' => ['Mihomo', 'MetaCubeX/mihomo', 'v'],
        'sing[ _-]box' => ['sing-box', 'SagerNet/sing-box', 'v'],
        'xray(?:-core)?' => ['Xray-core', 'XTLS/Xray-core', 'v'],
    ];
    private const PORT_ERROR = '~\b(?:address already in use|eaddrinuse|wsaeaddrinuse|(?:local (?:proxy )?)?port (?:is )?(?:already )?in use|port conflict)\b|端口(?:已被占用|被占用|已占用|占用|冲突)~iu';
    private const CONFIG_ERROR = '~invalid (?:config(?:uration)?|profile|yaml)|profile format is invalid|yaml.{0,20}(?:error|invalid|failed|错误)|配置.{0,4}(?:无效|错误|失败)|格式.{0,4}(?:无效|错误)~iu';
    private const DNS_ERROR = '~\bdns\b.{0,35}(?:timeout|fail|超时|失败)|域名解析(?:失败|超时)~iu';
    private const TLS_ERROR = '~\btls\b.{0,30}(?:handshake|certificate|握手|证书)|\bx509\b~iu';

    // Fixed publisher pages, with a narrowly selected passage for each topic.
    private const DOCUMENTS = [
        'winsock' => ['Microsoft Learn: Winsock WSAEADDRINUSE',
            'https://learn.microsoft.com/en-us/windows/win32/winsock/windows-sockets-error-codes-2',
            '//tr[td[1][contains(., "WSAEADDRINUSE")]]', 'Address already in use'],
        'linux-bind' => ['Linux man-pages: bind(2)', 'https://man7.org/linux/man-pages/man2/bind.2.html',
            '//pre[contains(., "EADDRINUSE")]', 'EADDRINUSE'],
        'mihomo-config' => ['Mihomo: YAML syntax', 'https://wiki.metacubex.one/en/handbook/syntax/',
            '//main//p[contains(., "Tabs are not allowed")]', 'yaml'],
        'sing-box-config' => ['sing-box: Configuration check', 'https://sing-box.sagernet.org/configuration/',
            '//main//pre[contains(., "sing-box check")]', 'sing-box check'],
        'mihomo-dns' => ['Mihomo: DNS configuration', 'https://wiki.metacubex.one/en/config/dns/',
            '//main//p[contains(., "nameserver")]', 'nameserver'],
        'sing-box-dns' => ['sing-box: DNS configuration', 'https://sing-box.sagernet.org/configuration/dns/',
            '//main//p[contains(., "servers")]', 'servers'],
        'sing-box-tls' => ['sing-box: TLS configuration', 'https://sing-box.sagernet.org/configuration/shared/tls/',
            '//main//p[contains(., "hostname") and contains(., "certificate")]', 'hostname'],
    ];

    public static function enabled(): bool
    {
        return (bool) admin_setting('ticket_ai_official_lookup_enable', config('tickets.ai_official_lookup', false));
    }

    /** @return array{sources: list<array{title: string, url: string, excerpt: string}>}|null */
    public function search(string $text, array $knowledge = [], string $previousCustomerText = ''): ?array
    {
        // All matching is local; an exact release request exposes only the project and version.
        $text = self::withoutUrls($text);
        $projects = $this->projects($text);
        if ($projects === [] && !preg_match('~\b(?:clash|shadowrocket|stash|surge|loon)\b|小火箭~iu', $text)) {
            $previous = $this->projects(self::withoutUrls($previousCustomerText));
            if (count($previous) === 1) {
                $label = array_key_first($previous);
                [$repo, $prefix] = $previous[$label];
                $projects[$label] = [$repo, $prefix, []];
            }
        }

        $topic = null;
        $docs = [];
        if (preg_match(self::PORT_ERROR, $text)) {
            $topic = self::PORT_ERROR;
            if (!preg_match('~\b(?:android|macos|ios)\b~i', $text)) {
                $docs = match (true) {
                    (bool) preg_match('~\b(?:windows|winsock|wsaeaddrinuse)\b~i', $text) => ['winsock'],
                    (bool) preg_match('~\blinux\b~i', $text) => ['linux-bind'],
                    default => ['winsock', 'linux-bind'],
                };
            }
        } elseif (preg_match(self::CONFIG_ERROR, $text)) {
            $topic = self::CONFIG_ERROR;
            $docs = match (true) {
                isset($projects['Mihomo']) && !isset($projects['sing-box']) => ['mihomo-config'],
                isset($projects['sing-box']) && !isset($projects['Mihomo']) => ['sing-box-config'],
                default => [],
            };
        } elseif (preg_match(self::DNS_ERROR, $text)) {
            $topic = self::DNS_ERROR;
            $docs = match (true) {
                isset($projects['Mihomo']) && !isset($projects['sing-box']) => ['mihomo-dns'],
                isset($projects['sing-box']) && !isset($projects['Mihomo']) => ['sing-box-dns'],
                default => [],
            };
        } elseif (isset($projects['sing-box']) && preg_match(self::TLS_ERROR, $text)) {
            $topic = self::TLS_ERROR;
            $docs = ['sing-box-tls'];
        }

        // A generic library hit must not suppress a lookup for a specific error or release.
        $localPassages = array_map(static fn (array $item): string => ($item['title'] ?? '') . "\n" . ($item['body'] ?? ''), $knowledge);
        $topicCovered = false;
        foreach ($localPassages as $passage) {
            if ($topic !== null && preg_match($topic, $passage)) {
                $topicCovered = true;
                break;
            }
        }
        $releases = [];
        $versionQuestion = preg_match('~兼容|版本|支持|更新|升级|握手|报错|不识别|\b(?:compatib\w*|support\w*|version|update|upgrade|latest|handshake|error|invalid|fails?|timeout)\b~iu', $text);
        if ($versionQuestion || $docs !== []) {
            foreach ($projects as $label => [$repo, $prefix, $versions]) {
                if (count($versions) === 1) {
                    $versionMention = '~(?<![\w.])v?' . preg_quote($versions[0], '~') . '(?![\w.+-])~i';
                    foreach ($localPassages as $passage) {
                        if (stripos($passage, $label) !== false && preg_match($versionMention, $passage)
                            && ($topic === null || preg_match($topic, $passage))) {
                            continue 2;
                        }
                    }
                    $releases[] = [$label, $repo, $prefix . $versions[0]];
                } elseif ($versions === [] && count($projects) === 1 && preg_match('~\blatest\b|最新版|最新版本~iu', $text)) {
                    $releases[] = [$label, $repo, null];
                }
            }
        }
        if ($topicCovered) {
            $docs = [];
        }
        if ($docs === [] && $releases === []) {
            return null;
        }

        $sources = [];
        // At most two requests, each with a three-second timeout. Exact releases take precedence.
        foreach (array_slice($releases, 0, 2) as [$label, $repo, $tag]) {
            $source = $this->release($label, $repo, $tag, $text);
            if ($source !== null) {
                $sources[] = $source;
            }
        }
        foreach (array_slice($docs, 0, max(0, 2 - count($releases))) as $doc) {
            $source = $this->document(self::DOCUMENTS[$doc]);
            if ($source !== null) {
                $sources[] = $source;
            }
        }

        return ['sources' => $sources];
    }

    public function verifiedReply(array $sources): ?array
    {
        foreach ($sources as $source) {
            foreach (['winsock' => 'port', 'linux-bind' => 'port', 'mihomo-config' => 'mihomo-config',
                'sing-box-config' => 'sing-box-config', 'sing-box-tls' => 'sing-box-tls'] as $id => $kind) {
                if ($source['url'] === self::DOCUMENTS[$id][1] && $this->supportsReply($id, $source['excerpt'])) {
                    return ['kind' => $kind, 'source' => $id];
                }
            }
        }

        foreach ($sources as $source) {
            foreach (self::PROJECTS as [$label, $repo]) {
                $prefix = 'https://github.com/' . $repo . '/releases/tag/';
                if (!str_starts_with($source['url'], $prefix)) {
                    continue;
                }
                $tag = substr($source['url'], strlen($prefix));
                if (preg_match('/\Av?[0-9]{1,3}(?:\.[0-9]{1,3}){2}\z/', $tag)
                    && str_starts_with($source['excerpt'], 'Published release: ' . $tag . '.')) {
                    return ['kind' => 'release', 'source' => $repo, 'tag' => $tag];
                }
            }
        }

        return null;
    }

    private function supportsReply(string $source, string $excerpt): bool
    {
        $required = match ($source) {
            'winsock' => ['WSAEADDRINUSE', 'already in use'],
            'linux-bind' => ['EADDRINUSE', 'already in use'],
            'mihomo-config' => ['indentation', 'spaces'],
            'sing-box-config' => ['sing-box check'],
            'sing-box-tls' => ['hostname', 'certificate'],
        };
        foreach ($required as $text) {
            if (stripos($excerpt, $text) === false) {
                return false;
            }
        }
        return true;
    }

    public function controlledDraft(array $proof): ?string
    {
        $kind = $proof['kind'] ?? null;
        $source = $proof['source'] ?? null;
        if (!is_string($kind) || !is_string($source)) {
            return null;
        }
        if ($kind === 'release') {
            $tag = $proof['tag'] ?? null;
            if (!is_string($tag) || !preg_match('/\Av?[0-9]{1,3}(?:\.[0-9]{1,3}){2}\z/', $tag)) {
                return null;
            }
            foreach (self::PROJECTS as [$label, $repo]) {
                if ($source === $repo) {
                    return "官方仓库已发布 {$label} {$tag}。仅凭发布记录不能确认当前客户端、内核和传输协议的组合兼容。请先在客户端确认实际选用的内核和版本，并贴出具体报错（不用重发已有信息）；请勿发送订阅链接或密码。\n\n官方发布记录：https://github.com/{$repo}/releases/tag/{$tag}";
                }
            }
            return null;
        }
        $allowed = $kind === 'port' ? ['winsock', 'linux-bind'] : [$kind];
        if (!isset(self::REPLIES[$kind], self::DOCUMENTS[$source]) || !in_array($source, $allowed, true)) {
            return null;
        }
        [$title, $url] = self::DOCUMENTS[$source];

        return self::REPLIES[$kind] . "\n\n官方参考：" . $title . ' ' . $url;
    }

    private function projects(string $text): array
    {
        $projects = [];
        foreach (self::PROJECTS as $aliases => [$label, $repo, $prefix]) {
            $pattern = '(?<![a-z0-9_-])(?:' . $aliases . ')(?![a-z0-9_-])';
            if (preg_match('~' . $pattern . '~i', $text) !== 1) {
                continue;
            }
            preg_match_all('~' . $pattern
                . '\s*(?:(?:version|版本)\s*)?[:：]?\s*v?([0-9]{1,3}(?:\.[0-9]{1,3}){2})(?![\w.+-])~iu', $text, $matches);
            $projects[$label] = [$repo, $prefix, array_values(array_unique($matches[1] ?? []))];
        }

        return $projects;
    }

    private function document(array $spec): ?array
    {
        [$title, $url, $xpath, $anchor] = $spec;
        $excerpt = $this->fetch($url, 'text/html', static function (string $body) use ($xpath, $anchor): ?string {
            $document = new DOMDocument();
            if ($body === '' || !$document->loadHTML('<?xml encoding="UTF-8">' . $body, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
                return null;
            }
            $passage = (new DOMXPath($document))->query($xpath)?->item(0)?->textContent ?? '';
            $passage = self::plainText($passage);
            $position = mb_stripos($passage, $anchor);

            return $position === false ? null : mb_substr($passage, max(0, $position - 35), 400);
        });

        return $excerpt === null ? null : compact('title', 'url', 'excerpt');
    }

    private function release(string $label, string $repo, ?string $tag, string $text): ?array
    {
        $endpoint = 'https://api.github.com/repos/' . $repo . '/releases/' . ($tag === null ? 'latest' : 'tags/' . $tag);
        $release = $this->fetch($endpoint, 'application/vnd.github+json', static function (string $body) use ($tag): ?array {
            $data = json_decode($body, true);
            $actualTag = $data['tag_name'] ?? null;
            if (($data['draft'] ?? true) !== false || !is_bool($data['prerelease'] ?? null)
                || !is_string($actualTag) || !preg_match('/\Av?[0-9]{1,3}(?:\.[0-9]{1,3}){2}\z/', $actualTag)
                || ($tag !== null && $tag !== $actualTag) || ($tag === null && $data['prerelease'])) {
                return null;
            }

            return ['tag' => $actualTag, 'prerelease' => $data['prerelease'],
                'body' => is_string($data['body'] ?? null) ? mb_substr($data['body'], 0, 16000) : ''];
        });
        if ($release === null) {
            return null;
        }

        // Keep matching notes only; a published tag alone is not evidence of protocol support.
        preg_match_all('~\b(?:trojan|vless|vmess|hysteria2?|tls|ech|reality|trusttunnel|dns|yaml|xray|sing[ _-]box|mihomo|tun)\b~i', $text, $matches);
        $terms = array_values(array_unique(array_map('strtolower', $matches[0])));
        $notes = [];
        foreach (preg_split('/\R/u', $release['body']) ?: [] as $line) {
            foreach ($terms as $term) {
                if (preg_match('~\b' . preg_quote($term, '~') . '\b~i', $line)) {
                    $notes[] = mb_substr(self::plainText($line), 0, 220);
                    break;
                }
            }
            if (count($notes) === 3) {
                break;
            }
        }
        $title = $label . ' ' . $release['tag'] . ' release notes';
        $url = 'https://github.com/' . $repo . '/releases/tag/' . $release['tag'];
        $excerpt = ($release['prerelease'] ? 'Published prerelease: ' : 'Published release: ') . $release['tag'] . '.'
            . ($notes === [] ? ' No matching compatibility note extracted.' : "\n" . implode("\n", $notes));

        return compact('title', 'url', 'excerpt');
    }

    private function fetch(string $url, string $accept, callable $parse): mixed
    {
        try {
            $cached = Cache::get('ticket_ai_official:v1:' . hash('sha256', $url));
            if (is_array($cached) && array_key_exists('value', $cached)) {
                return $cached['value'];
            }
        } catch (Throwable) {
            return null;
        }

        try {
            $response = Http::withHeaders(['User-Agent' => 'Keliboard-OfficialDocs', 'Accept' => $accept])
                ->connectTimeout(2)->timeout(3)->withOptions([
                    'allow_redirects' => false,
                    'on_headers' => static function (ResponseInterface $response): void {
                        if ((int) $response->getHeaderLine('Content-Length') > self::MAX_BYTES) {
                            throw new RuntimeException('Official document exceeds the lookup budget');
                        }
                    },
                    'progress' => static function (int $total, int $downloaded): void {
                        if (max($total, $downloaded) > self::MAX_BYTES) {
                            throw new RuntimeException('Official document exceeds the lookup budget');
                        }
                    },
                ])->get($url);
            $body = $response->body();
            $value = $response->ok() && strlen($body) <= self::MAX_BYTES ? $parse($body) : null;
        } catch (Throwable) {
            $value = null;
        }
        try {
            Cache::put('ticket_ai_official:v1:' . hash('sha256', $url), ['value' => $value], $value === null ? 60 : 1800);
        } catch (Throwable) {
            // Cache unavailability must not discard a verified response.
        }

        return $value;
    }

    private static function plainText(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', self::withoutUrls(strip_tags($text))) ?? '');
    }

    private static function withoutUrls(string $text): string
    {
        return preg_replace('~(?:[a-z][a-z0-9+.-]*://|www\.)[^\s<>"\']+~iu', ' ', $text) ?? '';
    }
}
