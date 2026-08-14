<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Knowledge;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class OfficialKnowledgePackService
{
    public const SOURCE_TYPE = 'official';

    private const SOURCE_COLUMNS = [
        'source_type',
        'source_key',
        'source_version',
        'source_hash',
        'source_synced_at',
    ];

    public function __construct(private ?string $packDirectory = null)
    {
        $this->packDirectory = $packDirectory
            ?: database_path('knowledge-packs/client-guides');
    }

    public function status(): array
    {
        $pack = $this->loadPack();
        $schemaReady = $this->hasSourceColumns();
        $existing = $schemaReady
            ? Knowledge::query()
                ->whereIn('source_key', array_column($pack['articles'], 'source_key'))
                ->get()
                ->keyBy('source_key')
            : collect();

        $articles = array_map(function (array $article) use ($existing, $schemaReady): array {
            /** @var Knowledge|null $knowledge */
            $knowledge = $schemaReady ? $existing->get($article['source_key']) : null;
            $state = $schemaReady ? $this->articleState($article, $knowledge) : 'migration_required';

            return [
                'source_key' => $article['source_key'],
                'slug' => $article['slug'],
                'title' => $article['title'],
                'category' => $article['category'],
                'target_version' => $article['source_version'],
                'installed_version' => $knowledge?->source_version,
                'knowledge_id' => $knowledge ? (int) $knowledge->id : null,
                'state' => $state,
            ];
        }, $pack['articles']);

        $counts = array_count_values(array_column($articles, 'state'));

        return [
            'schema_ready' => $schemaReady,
            'pack' => $pack['name'],
            'title' => $pack['title'],
            'version' => $pack['version'],
            'description' => $pack['description'],
            'summary' => [
                'total' => count($articles),
                'current' => (int) ($counts['current'] ?? 0),
                'missing' => (int) ($counts['missing'] ?? 0),
                'update_available' => (int) ($counts['update_available'] ?? 0),
                'local_modified' => (int) ($counts['local_modified'] ?? 0),
                'migration_required' => (int) ($counts['migration_required'] ?? 0),
            ],
            'articles' => $articles,
        ];
    }

    public function sync(): array
    {
        $this->assertSourceColumns();
        $pack = $this->loadPack();
        $summary = [
            'created' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'skipped_local_modified' => 0,
        ];

        DB::transaction(function () use ($pack, &$summary): void {
            foreach ($pack['articles'] as $article) {
                /** @var Knowledge|null $knowledge */
                $knowledge = Knowledge::query()
                    ->where('source_key', $article['source_key'])
                    ->lockForUpdate()
                    ->first();
                $state = $this->articleState($article, $knowledge);

                if ($state === 'missing') {
                    Knowledge::query()->create($this->installPayload($article));
                    $summary['created']++;
                    continue;
                }
                if ($state === 'local_modified') {
                    $summary['skipped_local_modified']++;
                    continue;
                }
                if ($state === 'current') {
                    $summary['unchanged']++;
                    continue;
                }

                $knowledge->update($this->installPayload($article));
                $summary['updated']++;
            }
        });

        return [
            'pack' => $pack['name'],
            'version' => $pack['version'],
            'summary' => $summary,
            'status' => $this->status(),
        ];
    }

    private function articleState(array $article, ?Knowledge $knowledge): string
    {
        if (!$knowledge) {
            return 'missing';
        }

        $installedHash = trim((string) $knowledge->source_hash);
        if ($installedHash === '' || !hash_equals($installedHash, $this->payloadHash($this->payloadFromKnowledge($knowledge)))) {
            return 'local_modified';
        }

        return hash_equals($installedHash, $article['source_hash'])
            ? 'current'
            : 'update_available';
    }

    private function installPayload(array $article): array
    {
        return array_merge($article['payload'], [
            'source_type' => self::SOURCE_TYPE,
            'source_key' => $article['source_key'],
            'source_version' => $article['source_version'],
            'source_hash' => $article['source_hash'],
            'source_synced_at' => time(),
        ]);
    }

    private function payloadFromKnowledge(Knowledge $knowledge): array
    {
        return $this->normalizePayload([
            'category' => $knowledge->category,
            'language' => $knowledge->language,
            'title' => $knowledge->title,
            'body' => $knowledge->body,
            'sort' => $knowledge->sort,
            'show' => $knowledge->show,
            'scope_type' => $knowledge->scope_type,
            'site_id' => $knowledge->site_id,
            'agent_user_id' => $knowledge->agent_user_id,
            'agent_domain_id' => $knowledge->agent_domain_id,
        ]);
    }

    private function loadPack(): array
    {
        $directory = realpath((string) $this->packDirectory);
        if ($directory === false) {
            throw new RuntimeException('官方文档包目录不存在');
        }

        $manifestPath = $directory . DIRECTORY_SEPARATOR . 'manifest.json';
        $manifest = json_decode((string) @file_get_contents($manifestPath), true);
        if (!is_array($manifest)) {
            throw new RuntimeException('官方文档包 manifest.json 无效');
        }

        $name = trim((string) ($manifest['name'] ?? ''));
        $version = trim((string) ($manifest['version'] ?? ''));
        $articles = $manifest['articles'] ?? null;
        if ($name === '' || $version === '' || !is_array($articles)) {
            throw new RuntimeException('官方文档包缺少名称、版本或文章列表');
        }

        $resolvedArticles = [];
        $seenKeys = [];
        foreach ($articles as $index => $article) {
            if (!is_array($article)) {
                throw new RuntimeException("官方文档包第 {$index} 篇文章配置无效");
            }

            $slug = trim((string) ($article['slug'] ?? ''));
            $bodyPath = trim((string) ($article['body'] ?? ''));
            if (!preg_match('/^[a-z0-9][a-z0-9-]*$/', $slug) || $bodyPath === '') {
                throw new RuntimeException("官方文档包第 {$index} 篇文章标识无效");
            }

            $sourceKey = $name . '/' . $slug;
            if (isset($seenKeys[$sourceKey])) {
                throw new RuntimeException("官方文档包文章标识重复：{$sourceKey}");
            }
            $seenKeys[$sourceKey] = true;

            $resolvedBodyPath = realpath($directory . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $bodyPath));
            if ($resolvedBodyPath === false || !str_starts_with($resolvedBodyPath, $directory . DIRECTORY_SEPARATOR)) {
                throw new RuntimeException("官方文档正文不存在或路径越界：{$bodyPath}");
            }

            $payload = $this->normalizePayload([
                'category' => $article['category'] ?? $manifest['category'] ?? '',
                'language' => $article['language'] ?? $manifest['language'] ?? 'zh-CN',
                'title' => $article['title'] ?? '',
                'body' => (string) file_get_contents($resolvedBodyPath),
                'sort' => $article['sort'] ?? null,
                'show' => $article['show'] ?? true,
                'scope_type' => $article['scope_type'] ?? Knowledge::SCOPE_GLOBAL,
                'site_id' => $article['site_id'] ?? null,
                'agent_user_id' => $article['agent_user_id'] ?? null,
                'agent_domain_id' => $article['agent_domain_id'] ?? null,
            ]);

            if ($payload['category'] === '' || $payload['title'] === '') {
                throw new RuntimeException("官方文档包文章标题或分类为空：{$sourceKey}");
            }

            $resolvedArticles[] = [
                'source_key' => $sourceKey,
                'source_version' => $version,
                'source_hash' => $this->payloadHash($payload),
                'slug' => $slug,
                'title' => $payload['title'],
                'category' => $payload['category'],
                'payload' => $payload,
            ];
        }

        return [
            'name' => $name,
            'title' => trim((string) ($manifest['title'] ?? 'KeliBoard 官方使用文档')),
            'version' => $version,
            'description' => trim((string) ($manifest['description'] ?? '')),
            'articles' => $resolvedArticles,
        ];
    }

    private function normalizePayload(array $payload): array
    {
        $scope = (string) ($payload['scope_type'] ?? Knowledge::SCOPE_GLOBAL);
        if (!in_array($scope, Knowledge::SCOPE_TYPES, true)) {
            throw new RuntimeException("官方文档适用范围无效：{$scope}");
        }

        return [
            'category' => trim((string) ($payload['category'] ?? '')),
            'language' => trim((string) ($payload['language'] ?? 'zh-CN')),
            'title' => trim((string) ($payload['title'] ?? '')),
            'body' => (string) ($payload['body'] ?? ''),
            'sort' => $payload['sort'] === null ? null : (int) $payload['sort'],
            'show' => (bool) ($payload['show'] ?? true),
            'scope_type' => $scope,
            'site_id' => $scope === Knowledge::SCOPE_SITE && !empty($payload['site_id']) ? (int) $payload['site_id'] : null,
            'agent_user_id' => $scope === Knowledge::SCOPE_AGENT && !empty($payload['agent_user_id']) ? (int) $payload['agent_user_id'] : null,
            'agent_domain_id' => $scope === Knowledge::SCOPE_AGENT && !empty($payload['agent_domain_id']) ? (int) $payload['agent_domain_id'] : null,
        ];
    }

    private function payloadHash(array $payload): string
    {
        return hash('sha256', (string) json_encode(
            $this->normalizePayload($payload),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ));
    }

    private function assertSourceColumns(): void
    {
        if (!$this->hasSourceColumns()) {
            throw new RuntimeException('请先执行数据库迁移，再同步官方文档');
        }
    }

    private function hasSourceColumns(): bool
    {
        if (!Schema::hasTable('v2_knowledge')) {
            return false;
        }

        return collect(self::SOURCE_COLUMNS)
            ->every(fn (string $column): bool => Schema::hasColumn('v2_knowledge', $column));
    }
}
