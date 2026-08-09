<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Models\MarketingRule;
use App\Models\MarketingTemplate;
use App\Models\MessageDispatchLog;
use App\Models\MessageDispatchTask;
use App\Services\MarketingAutomationService;
use App\Services\MessageDispatchService;
use App\Services\MessageOpsSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class MarketingController extends Controller
{
    private MarketingAutomationService $marketingService;
    private MessageDispatchService $dispatchService;

    public function __construct(MarketingAutomationService $marketingService, MessageDispatchService $dispatchService)
    {
        $this->marketingService = $marketingService;
        $this->dispatchService = $dispatchService;
    }

    public function overview(Request $request)
    {
        if ($response = $this->ensureMessageOpsEnabled()) {
            return $response;
        }
        $this->marketingService->seedDefaults();
        $scope = $this->scopeFromRequest($request);
        $templateQuery = MarketingTemplate::query();
        $pendingTaskQuery = MessageDispatchTask::query()->where('state', MessageDispatchTask::STATE_PENDING);
        $sendingTaskQuery = MessageDispatchTask::query()->where('state', MessageDispatchTask::STATE_SENDING);
        $this->applyTemplateScope($templateQuery, $scope);
        $this->applyStrictScope($pendingTaskQuery, 'v2_message_dispatch_task', $scope);
        $this->applyStrictScope($sendingTaskQuery, 'v2_message_dispatch_task', $scope);

        return $this->success([
            'health' => $this->dispatchService->getProviderHealth(),
            'quota' => $this->dispatchService->getQuotaOverview(),
            'counts' => [
                'rules_total' => MarketingRule::query()->count(),
                'rules_enabled' => MarketingRule::query()->where('enabled', true)->count(),
                'templates_total' => $templateQuery->count(),
                'pending_tasks' => $pendingTaskQuery->count(),
                'sending_tasks' => $sendingTaskQuery->count(),
            ],
        ]);
    }

    public function rules()
    {
        if ($response = $this->ensureMessageOpsEnabled()) {
            return $response;
        }
        $this->marketingService->seedDefaults();
        $rules = MarketingRule::query()
            ->with(['emailTemplate:id,name,code,channel', 'telegramTemplate:id,name,code,channel'])
            ->orderBy('priority')
            ->get();

        return $this->success($rules);
    }

    public function updateRule(Request $request)
    {
        if ($response = $this->ensureMessageOpsEnabled()) {
            return $response;
        }
        $data = $request->validate([
            'id' => 'required|integer|exists:v2_marketing_rule,id',
            'enabled' => 'required|boolean',
            'email_enabled' => 'required|boolean',
            'telegram_enabled' => 'required|boolean',
            'email_template_id' => 'nullable|integer|exists:v2_marketing_template,id',
            'telegram_template_id' => 'nullable|integer|exists:v2_marketing_template,id',
            'cooldown_hours' => 'required|integer|min:1|max:720',
            'daily_user_limit' => 'required|integer|min:1|max:10',
            'priority' => 'required|integer|min:1|max:999',
        ]);

        $rule = MarketingRule::query()->findOrFail($data['id']);
        $rule->update($data);
        $this->cancelDisabledRuleTasks($rule);

        return $this->success(true);
    }

    public function templates(Request $request)
    {
        if ($response = $this->ensureMessageOpsEnabled()) {
            return $response;
        }
        $this->marketingService->seedDefaults();
        $channel = trim((string) $request->input('channel', ''));
        $scope = $this->scopeFromRequest($request);

        $query = MarketingTemplate::query()->orderByDesc('id');
        $this->applyTemplateScope($query, $scope);
        if ($channel !== '') {
            $query->where('channel', $channel);
        }

        return $this->success($query->get());
    }

    public function saveTemplate(Request $request)
    {
        if ($response = $this->ensureMessageOpsEnabled()) {
            return $response;
        }
        $data = $request->validate([
            'id' => 'nullable|integer|exists:v2_marketing_template,id',
            'code' => 'required|string|max:64',
            'name' => 'required|string|max:128',
            'channel' => 'required|in:email,telegram',
            'message_type' => 'required|in:transactional,lifecycle,marketing',
            'subject' => 'nullable|string|max:255',
            'content' => 'required|string|max:20000',
            'enabled' => 'required|boolean',
            'scope_type' => 'nullable|in:global,site,agent',
            'site_id' => 'nullable|integer',
            'agent_user_id' => 'nullable|integer',
            'agent_domain_id' => 'nullable|integer',
        ]);
        $scopeData = $this->templateScopePayload($request);
        if ($scopeData === false) {
            return $this->fail([422, '站点不能为空']);
        }

        $template = isset($data['id'])
            ? MarketingTemplate::query()->findOrFail($data['id'])
            : new MarketingTemplate();

        unset($data['scope_type'], $data['site_id'], $data['agent_user_id'], $data['agent_domain_id']);
        $template->fill($data);
        if (is_array($scopeData)) {
            $template->fill($scopeData);
        }
        if (!$template->exists) {
            $template->is_system = false;
        }
        $template->save();

        return $this->success($template);
    }

    public function logs(Request $request)
    {
        if ($response = $this->ensureMessageOpsEnabled()) {
            return $response;
        }
        $current = max(1, (int) $request->input('current', 1));
        $pageSize = max(1, min(100, (int) $request->input('pageSize', 20)));
        $email = trim((string) $request->input('email', ''));
        $status = trim((string) $request->input('status', ''));
        $messageType = trim((string) $request->input('message_type', ''));
        $channel = trim((string) $request->input('channel', ''));
        $scope = $this->scopeFromRequest($request);

        $query = MessageDispatchLog::query()
            ->with(['rule:id,code,name', 'template:id,code,name'])
            ->orderByDesc('id');
        $this->applyStrictScope($query, 'v2_message_dispatch_log', $scope);

        if ($email !== '') {
            $query->where('to_address', 'like', '%' . $email . '%');
        }
        if ($status !== '') {
            $query->where('status', $status);
        }
        if ($messageType !== '') {
            $query->where('message_type', $messageType);
        }
        if ($channel !== '') {
            $query->where('channel', $channel);
        }

        $page = $query->paginate($pageSize, ['*'], 'page', $current);
        return $this->paginate($page);
    }

    public function saveLogNote(Request $request)
    {
        if ($response = $this->ensureMessageOpsEnabled()) {
            return $response;
        }
        $data = $request->validate([
            'id' => 'required|integer|exists:v2_message_dispatch_log,id',
            'note' => 'nullable|string|max:5000',
        ]);

        $log = MessageDispatchLog::query()->findOrFail($data['id']);
        $saved = $this->dispatchService->saveLogNote($log, $data['note'] ?? null, $request->user()?->id);

        return $this->success($saved);
    }

    private function cancelDisabledRuleTasks(MarketingRule $rule): int
    {
        $query = MessageDispatchTask::query()
            ->where('rule_id', $rule->id)
            ->whereIn('state', [
                MessageDispatchTask::STATE_PENDING,
                MessageDispatchTask::STATE_SENDING,
            ]);

        if (!$rule->enabled) {
            $reason = 'marketing rule disabled by admin';
        } else {
            $disabledChannels = [];
            if (!$rule->email_enabled) {
                $disabledChannels[] = MarketingTemplate::CHANNEL_EMAIL;
            }
            if (!$rule->telegram_enabled) {
                $disabledChannels[] = MarketingTemplate::CHANNEL_TELEGRAM;
            }
            if ($disabledChannels === []) {
                return 0;
            }

            $query->whereIn('channel', $disabledChannels);
            $reason = 'marketing channel disabled by admin';
        }

        return $query->update([
            'state' => MessageDispatchTask::STATE_CANCELLED,
            'reserved_at' => null,
            'last_error' => $reason,
            'updated_at' => time(),
        ]);
    }

    private function ensureMessageOpsEnabled(): ?JsonResponse
    {
        if (MessageOpsSettings::enabled()) {
            return null;
        }

        return $this->fail([403, '营销运营功能未启用']);
    }

    /**
     * @return array{type: string, site_id: ?int, agent_user_id: ?int, agent_domain_id: ?int}
     */
    private function scopeFromRequest(Request $request): array
    {
        $type = strtolower(trim((string) $request->input('scope_type', 'all')));
        if (!in_array($type, ['all', 'global', 'site', 'agent'], true)) {
            $type = 'all';
        }

        return [
            'type' => $type,
            'site_id' => $this->positiveIntOrNull($request->input('site_id')),
            'agent_user_id' => $this->positiveIntOrNull($request->input('agent_user_id')),
            'agent_domain_id' => $this->positiveIntOrNull($request->input('agent_domain_id')),
        ];
    }

    private function applyTemplateScope(Builder $query, array $scope): void
    {
        if ($scope['type'] === 'all' || !$this->hasColumn('v2_marketing_template', 'site_id')) {
            return;
        }

        if ($scope['type'] === 'global') {
            $this->whereGlobalScope($query, 'v2_marketing_template');
            return;
        }

        if ($scope['type'] === 'site' && $scope['site_id']) {
            $siteId = (int) $scope['site_id'];
            $query->where(function (Builder $builder) use ($siteId): void {
                $builder->where('v2_marketing_template.site_id', $siteId)
                    ->orWhere(function (Builder $global): void {
                        $this->whereGlobalScope($global, 'v2_marketing_template');
                    });
            });
            return;
        }

        if ($scope['type'] === 'agent' && $scope['agent_user_id'] && $this->hasColumn('v2_marketing_template', 'agent_user_id')) {
            $agentUserId = (int) $scope['agent_user_id'];
            $agentDomainId = $scope['agent_domain_id'];
            $query->where(function (Builder $builder) use ($agentUserId, $agentDomainId): void {
                $builder->where('v2_marketing_template.agent_user_id', $agentUserId);
                if ($agentDomainId && $this->hasColumn('v2_marketing_template', 'agent_domain_id')) {
                    $builder->where(function (Builder $domain) use ($agentDomainId): void {
                        $domain->whereNull('v2_marketing_template.agent_domain_id')
                            ->orWhere('v2_marketing_template.agent_domain_id', (int) $agentDomainId);
                    });
                }
            });
        }
    }

    private function applyStrictScope(Builder $query, string $table, array $scope): void
    {
        if ($scope['type'] === 'all' || !$this->hasColumn($table, 'site_id')) {
            return;
        }

        if ($scope['type'] === 'global') {
            $this->whereGlobalScope($query, $table);
            return;
        }

        if ($scope['type'] === 'site' && $scope['site_id']) {
            $query->where($table . '.site_id', (int) $scope['site_id']);
            return;
        }

        if ($scope['type'] === 'agent' && $scope['agent_user_id'] && $this->hasColumn($table, 'agent_user_id')) {
            $query->where($table . '.agent_user_id', (int) $scope['agent_user_id']);
            if ($scope['agent_domain_id'] && $this->hasColumn($table, 'agent_domain_id')) {
                $query->where(function (Builder $builder) use ($table, $scope): void {
                    $builder->whereNull($table . '.agent_domain_id')
                        ->orWhere($table . '.agent_domain_id', (int) $scope['agent_domain_id']);
                });
            }
        }
    }

    /**
     * @return array<string, mixed>|false|null
     */
    private function templateScopePayload(Request $request): array|false|null
    {
        if (!$this->hasColumn('v2_marketing_template', 'scope_type') || !$request->has('scope_type')) {
            return null;
        }

        $scope = $this->scopeFromRequest($request);
        if ($scope['type'] === 'global' || $scope['type'] === 'all') {
            return [
                'scope_type' => 'global',
                'site_id' => null,
                'agent_user_id' => null,
                'agent_domain_id' => null,
            ];
        }

        if ($scope['type'] === 'site') {
            if (!$scope['site_id']) {
                return false;
            }

            return [
                'scope_type' => 'site',
                'site_id' => (int) $scope['site_id'],
                'agent_user_id' => null,
                'agent_domain_id' => null,
            ];
        }

        if ($scope['type'] === 'agent' && $scope['agent_user_id']) {
            return [
                'scope_type' => 'agent',
                'site_id' => $scope['site_id'],
                'agent_user_id' => (int) $scope['agent_user_id'],
                'agent_domain_id' => $scope['agent_domain_id'],
            ];
        }

        return false;
    }

    private function whereGlobalScope(Builder $query, string $table): void
    {
        $query->where(function (Builder $builder) use ($table): void {
            $builder->whereNull($table . '.site_id');
            if ($this->hasColumn($table, 'agent_user_id')) {
                $builder->whereNull($table . '.agent_user_id');
            }
        });
    }

    private function positiveIntOrNull(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric($value)) {
            return null;
        }

        $value = (int) $value;

        return $value > 0 ? $value : null;
    }

    private function hasColumn(string $table, string $column): bool
    {
        try {
            return app('db')->connection()->getSchemaBuilder()->hasColumn($table, $column);
        } catch (\Throwable) {
            return false;
        }
    }
}
