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
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class MarketingController extends Controller
{
    private MarketingAutomationService $marketingService;
    private MessageDispatchService $dispatchService;

    public function __construct(MarketingAutomationService $marketingService, MessageDispatchService $dispatchService)
    {
        $this->marketingService = $marketingService;
        $this->dispatchService = $dispatchService;
    }

    public function overview()
    {
        if ($response = $this->ensureMessageOpsEnabled()) {
            return $response;
        }
        $this->marketingService->seedDefaults();

        return $this->success([
            'health' => $this->dispatchService->getProviderHealth(),
            'quota' => $this->dispatchService->getQuotaOverview(),
            'counts' => [
                'rules_total' => MarketingRule::query()->count(),
                'rules_enabled' => MarketingRule::query()->where('enabled', true)->count(),
                'templates_total' => MarketingTemplate::query()->count(),
                'pending_tasks' => MessageDispatchTask::query()->where('state', MessageDispatchTask::STATE_PENDING)->count(),
                'sending_tasks' => MessageDispatchTask::query()->where('state', MessageDispatchTask::STATE_SENDING)->count(),
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

        return $this->success(true);
    }

    public function templates(Request $request)
    {
        if ($response = $this->ensureMessageOpsEnabled()) {
            return $response;
        }
        $this->marketingService->seedDefaults();
        $channel = trim((string) $request->input('channel', ''));

        $query = MarketingTemplate::query()->orderByDesc('id');
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
        ]);

        $template = isset($data['id'])
            ? MarketingTemplate::query()->findOrFail($data['id'])
            : new MarketingTemplate();

        $template->fill($data);
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

        $query = MessageDispatchLog::query()
            ->with(['rule:id,code,name', 'template:id,code,name'])
            ->orderByDesc('id');

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

    private function ensureMessageOpsEnabled(): ?JsonResponse
    {
        if (MessageOpsSettings::enabled()) {
            return null;
        }

        return $this->fail([403, '营销运营功能未启用']);
    }
}
