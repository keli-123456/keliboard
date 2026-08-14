<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Http\Resources\KnowledgeResource;
use App\Models\Knowledge;
use App\Models\User;
use App\Services\KnowledgeContextService;
use App\Services\SubscriptionProxy\SubscriptionProxyProbeService;
use App\Services\UserService;
use App\Utils\Helper;
use Illuminate\Http\Request;

class KnowledgeController extends Controller
{
    private UserService $userService;

    public function __construct(
        UserService $userService,
        private KnowledgeContextService $knowledgeContextService,
        private SubscriptionProxyProbeService $subscriptionProxyProbeService
    ) {
        $this->userService = $userService;
    }

    public function fetch(Request $request)
    {
        $request->validate([
            'id' => 'nullable|sometimes|integer|min:1',
            'language' => 'nullable|sometimes|string|max:10',
            'keyword' => 'nullable|sometimes|string|max:255',
        ]);

        return $request->input('id')
            ? $this->fetchSingle($request)
            : $this->fetchList($request);
    }

    private function fetchSingle(Request $request)
    {
        /** @var User $user */
        $user = $request->user();
        $context = $this->knowledgeContextService->resolve($request, $user);
        $knowledge = $this->buildKnowledgeQuery(['*'], $context)
            ->where('id', $request->input('id'))
            ->first();

        if (!$knowledge) {
            return $this->fail([500, __('Article does not exist')]);
        }

        $knowledge = $this->processKnowledgeContent($knowledge->toArray(), $user, $context);

        return $this->success(KnowledgeResource::make($knowledge));
    }

    private function fetchList(Request $request)
    {
        /** @var User $user */
        $user = $request->user();
        $context = $this->knowledgeContextService->resolve($request, $user);
        $builder = $this->buildKnowledgeQuery(['id', 'category', 'title', 'updated_at', 'body'], $context)
            ->where('language', $request->input('language'))
            ->orderBy('sort', 'ASC');

        $keyword = $request->input('keyword');
        if ($keyword) {
            $builder = $builder->where(function ($query) use ($keyword) {
                $query->where('title', 'LIKE', "%{$keyword}%")
                    ->orWhere('body', 'LIKE', "%{$keyword}%");
            });
        }

        $knowledges = $builder->get()
            ->map(function ($knowledge) use ($user, $context) {
                $knowledge = $this->processKnowledgeContent($knowledge->toArray(), $user, $context);
                return KnowledgeResource::make($knowledge);
            })
            ->groupBy('category');

        return $this->success($knowledges);
    }

    private function buildKnowledgeQuery(array $select = ['*'], array $context = [])
    {
        $query = Knowledge::select($select)->where('show', 1);
        if (!$this->hasScopeColumns()) {
            return $query;
        }

        return $this->knowledgeContextService->applyScope($query, $context);
    }

    private function processKnowledgeContent(array $knowledge, User $user, array $context): array
    {
        if (!isset($knowledge['body'])) {
            return $knowledge;
        }

        if (!$this->userService->isAvailable($user)) {
            $this->formatAccessData($knowledge['body']);
        }
        $subscriptionProxy = $this->subscriptionProxyProbeService->userPayload((string) $user['token']);
        $subscribeUrl = (string) ($subscriptionProxy['subscribe_url'] ?: Helper::getSubscribeUrl($user['token']));
        $knowledge['body'] = $this->replacePlaceholders($knowledge['body'], $subscribeUrl, $context);

        return $knowledge;
    }

    private function formatAccessData(&$body): void
    {
        $rules = [
            [
                'type' => 'regex',
                'pattern' => '/<!--access start-->(.*?)<!--access end-->/s',
                'replacement' => '<div class="v2board-no-access">' . __('You must have a valid subscription to view content in this area') . '</div>'
            ]
        ];

        $this->applyReplacementRules($body, $rules);
    }

    private function replacePlaceholders(string $body, string $subscribeUrl, array $context): string
    {
        $replacements = [
            '{{siteName}}' => e((string) ($context['site_name'] ?? admin_setting('app_name', 'XBoard'))),
            '{{siteUrl}}' => e((string) ($context['site_url'] ?? '')),
            '{{supportName}}' => e((string) ($context['support_name'] ?? '')),
            '{{supportUrl}}' => e((string) ($context['support_url'] ?? '')),
            '{{telegramUrl}}' => e((string) ($context['telegram_url'] ?? '')),
            '{{subscribeUrl}}' => $subscribeUrl,
            '{{urlEncodeSubscribeUrl}}' => urlencode($subscribeUrl),
            '{{safeBase64SubscribeUrl}}' => str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($subscribeUrl)),
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $body);
    }

    private function hasScopeColumns(): bool
    {
        try {
            $schema = app('db')->connection()->getSchemaBuilder();

            return $schema->hasColumn('v2_knowledge', 'scope_type')
                && $schema->hasColumn('v2_knowledge', 'site_id')
                && $schema->hasColumn('v2_knowledge', 'agent_user_id')
                && $schema->hasColumn('v2_knowledge', 'agent_domain_id');
        } catch (\Throwable) {
            return false;
        }
    }

    private function applyReplacementRules(string &$body, array $rules): void
    {
        foreach ($rules as $rule) {
            if ($rule['type'] === 'regex') {
                $body = preg_replace($rule['pattern'], $rule['replacement'], $body);
            } else {
                $body = str_replace($rule['search'], $rule['replacement'], $body);
            }
        }
    }
}
