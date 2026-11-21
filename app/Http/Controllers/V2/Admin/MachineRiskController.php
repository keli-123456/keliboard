<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Models\MachineRiskEvent;
use App\Models\ServerMachine;
use App\Services\ServerMachine\MachineRiskAnalysis;
use App\Services\ServerMachine\MachineRiskCredentialActions;
use App\Services\ServerMachine\MachineRiskSettings;
use App\Services\ServerMachine\MachineRiskInvestigationService;
use App\Services\ServerMachine\MachineRiskUuidReview;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MachineRiskController extends Controller
{
    public function overview(Request $request, MachineRiskSettings $policy): JsonResponse
    {
        $data = $request->validate(['machine_id' => ['nullable', 'integer', 'min:1'], 'status' => ['nullable', 'in:open,recovered,priority_review,after_reset']]);
        $snapshot = $policy->snapshot();
        $events = []; $summary = ['open' => 0, 'recovered' => 0, 'priority_review' => 0, 'after_reset' => 0,
            'analyses_last_hour' => 0, 'waiting' => 0, 'following' => 0, 'next_budget_at' => null];
        if ($snapshot['available']) {
            $query = MachineRiskEvent::query()->when(isset($data['machine_id']), fn ($q) => $q->where('machine_id', $data['machine_id']));
            $summary['open'] = (clone $query)->where('status', 'open')->count();
            $summary['recovered'] = (clone $query)->where('status', 'recovered')->count();
            $matched = (clone $query)->where('evidence->priority_review->kind', 'cross_machine_presence')->where('evidence->priority_review->state', 'matched');
            $repeat = (clone $matched)->where('evidence->priority_review->repeated', 'after_reset')
                ->where(fn($q) => $q->whereNull('review_verdict')->orWhere('review_verdict', '<>', 'false_positive'));
            $summary['after_reset'] = (clone $repeat)->count();
            $priorityReview = (clone $matched)
                ->where(fn($q) => $q->whereNull('review_verdict')->orWhere('review_verdict', 'uncertain'));
            $summary['priority_review'] = (clone $priorityReview)->count();
            $summary['analyses_last_hour'] = DB::table('v2_machine_risk_ai_attempt')->where('created_at', '>=', time() - 3600)->count();
            $summary['waiting'] = (clone $query)->where('ai_status', 'waiting')->count();
            $summary['following'] = (clone $query)->whereHas('investigation', fn($q) => $q->whereIn('state', MachineRiskInvestigationService::ACTIVE))->count();
            $summary['next_budget_at'] = app(MachineRiskAnalysis::class)->nextBudgetAt($policy->settings()['hourly_budget']);
            $status = $data['status'] ?? null;
            if ($status === 'priority_review') $query = $priorityReview;
            elseif ($status === 'after_reset') $query = $repeat;
            elseif ($status !== null) $query->where('status', $status);
            $rows = $query->with('investigation')->orderByDesc('detected_at')->limit(50)->get();
            $machines = ServerMachine::query()->whereIn('id', $rows->pluck('machine_id')->unique())
                ->get(['id', 'name', 'is_active', 'token', 'load_status'])->keyBy('id');
            $events = $rows->map(fn ($event) => $this->payload($event, false, $machines->get($event->machine_id), true))->all();
        }
        $ready = false; $model = '';
        try {
            $provider = app(MachineRiskAnalysis::class)->providerSettings();
            $ready = ($provider['enabled'] ?? false) && trim((string) ($provider['api_key'] ?? '')) !== ''
                && trim((string) ($provider['base_url'] ?? '')) !== '' && trim((string) ($provider['model'] ?? '')) !== '';
            $model = mb_substr((string) ($provider['model'] ?? ''), 0, 120);
        } catch (\Throwable) {}
        $autoActions = []; $repeatedAccounts = null;
        try {
            $autoActions = MachineRiskCredentialActions::availableActions();
            if (($data['status'] ?? null) === 'after_reset' && !isset($data['machine_id'])) $repeatedAccounts = app(MachineRiskCredentialActions::class)->repeatedAccounts();
        } catch (\Throwable) {}
        return $this->success(['available' => $snapshot['available'], 'revision' => $snapshot['revision'],
            'settings' => $policy->settings(), 'summary' => $summary, 'events' => $events,
            'credential_actions' => $autoActions, 'daily_reset_limit' => MachineRiskCredentialActions::DAILY_LIMIT,
            'repeated_accounts' => $repeatedAccounts,
            'ai_ready' => (bool) $ready, 'ai_model' => $model, 'retention_days' => 7]);
    }

    public function detail(int $id): JsonResponse
    {
        abort_unless(app(MachineRiskSettings::class)->available(), 409, 'migration_required');
        return $this->success($this->payload(MachineRiskEvent::query()->findOrFail($id), true));
    }

    public function uuidReviewChallenge(Request $request, int $id, MachineRiskUuidReview $service): JsonResponse
    {
        abort_unless($request->user()?->is_admin, 403);
        abort_unless(app(MachineRiskSettings::class)->available(), 409, 'migration_required');
        $data = $request->validate(['ref' => ['required', 'string', 'regex:/^[a-f0-9]{16}$/D']]);
        try {
            return $this->success($service->challenge($id, $data['ref'], (int) $request->user()->id))->header('Cache-Control', 'no-store');
        } catch (\Throwable $error) {
            return $this->uuidReviewFailure($error);
        }
    }

    public function confirmUuidReview(Request $request, int $id, MachineRiskUuidReview $service): JsonResponse
    {
        abort_unless($request->user()?->is_admin, 403);
        abort_unless(app(MachineRiskSettings::class)->available(), 409, 'migration_required');
        $data = $request->validate(['ref' => ['required', 'string', 'regex:/^[a-f0-9]{16}$/D'],
            'challenge' => ['required', 'string', 'max:112'], 'digest' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'],
            'reference' => ['required', 'string', 'regex:/^IR-[0-9]{1,16}$/D'], 'confirmed' => ['accepted'],
            'apply_action' => ['sometimes', 'string', 'in:reset_uuid,reset_both']]);
        try {
            return $this->success($service->confirm($id, $data['ref'], (int) $request->user()->id,
                $data['challenge'], $data['digest'], $data['reference'], $data['apply_action'] ?? null));
        } catch (\Throwable $error) {
            return $this->uuidReviewFailure($error);
        }
    }

    private function uuidReviewFailure(\Throwable $error): JsonResponse
    {
        $code = $error->getMessage();
        if (in_array($code, ['case_evidence_changed', 'node_uuid_not_matched', 'node_credential_review_unavailable'], true)) {
            return response()->json(['message' => $code], 409);
        }
        // SQL errors from a failed rotation can contain credential bindings.
        return response()->json(['message' => 'credential_review_write_failed'], 503);
    }

    public function saveSettings(Request $request, MachineRiskSettings $policy): JsonResponse
    {
        $data = $request->validate(['enabled' => ['required', 'boolean'], 'auto_ai' => ['required', 'boolean'],
            'notify_admin' => ['required', 'boolean'], 'notify_followup' => ['sometimes', 'boolean'], 'hourly_budget' => ['required', 'integer', 'min:1', 'max:10'],
            'overlap' => ['sometimes', 'array:window_minutes,history_days,minimum_machines,repeat_minimum,action', 'size:5'],
            'overlap.window_minutes' => ['required_with:overlap', 'integer', 'min:1', 'max:30'],
            'overlap.history_days' => ['required_with:overlap', 'integer', 'min:1', 'max:7'],
            'overlap.minimum_machines' => ['required_with:overlap', 'integer', 'min:3', 'max:10'],
            'overlap.repeat_minimum' => ['required_with:overlap', 'integer', 'min:2', 'max:10'],
            'overlap.action' => ['required_with:overlap', 'in:' . implode(',', MachineRiskSettings::OVERLAP_ACTIONS)],
            'overlap_confirmed' => ['sometimes', 'boolean'],
            'expected_revision' => ['required', 'integer', 'min:1'], 'confirmed' => ['required', 'boolean']]);
        try {
            $settings = array_intersect_key($data, MachineRiskSettings::DEFAULTS);
            foreach (['enabled', 'auto_ai', 'notify_admin', 'notify_followup'] as $key) if (array_key_exists($key, $settings)) $settings[$key] = (bool) $settings[$key];
            $settings['hourly_budget'] = (int) $settings['hourly_budget'];
            if (isset($settings['overlap'])) {
                foreach (['window_minutes', 'history_days', 'minimum_machines', 'repeat_minimum'] as $key) $settings['overlap'][$key] = (int) $settings['overlap'][$key];
            }
            return $this->success($policy->save($settings,
                $data['expected_revision'], (int) $request->user()->id, (bool) $data['confirmed'], (bool) ($data['overlap_confirmed'] ?? false)));
        } catch (\RuntimeException $error) { return response()->json(['message' => $error->getMessage()], 409); }
    }

    public function analyze(int $id, MachineRiskAnalysis $analysis): JsonResponse
    {
        abort_unless(app(MachineRiskSettings::class)->available(), 409, 'migration_required');
        $event = MachineRiskEvent::query()->findOrFail($id);
        $reserved = $analysis->request($event);
        return $this->success(['queued' => $reserved, 'event' => $this->payload($event->fresh(), true)]);
    }

    public function review(Request $request, int $id): JsonResponse
    {
        abort_unless(app(MachineRiskSettings::class)->available(), 409, 'migration_required');
        $data = $request->validate(['verdict' => ['required', 'in:confirmed_signal,false_positive,uncertain']]);
        $event = DB::transaction(function () use ($id, $data, $request): MachineRiskEvent {
            $event = MachineRiskEvent::query()->lockForUpdate()->findOrFail($id);
            $event->forceFill(['review_verdict' => $data['verdict'], 'reviewed_by' => (int) $request->user()->id, 'reviewed_at' => time()])->save();
            if (($event->evidence['priority_review']['repeated'] ?? null) === 'after_reset') {
                app(MachineRiskCredentialActions::class)->reviewRepeat($id, $data['verdict']);
            }
            return $event;
        }, 2);
        return $this->success($this->payload($event, true));
    }

    private function payload(MachineRiskEvent $event, bool $detail, ?ServerMachine $machine = null, bool $preloaded = false): array
    {
        $result = $event->toArray();
        $priority = $result['evidence']['priority_review'] ?? [];
        $result['priority_review_pending'] = ($priority['kind'] ?? null) === 'cross_machine_presence'
            && ($priority['state'] ?? null) === 'matched'
            && in_array($event->review_verdict, [null, 'uncertain'], true);
        $result['after_reset'] = ($priority['kind'] ?? null) === 'cross_machine_presence'
            && ($priority['repeated'] ?? null) === 'after_reset' && $event->review_verdict !== 'false_positive';
        $result['credential_action'] = $result['evidence']['credential_action'] ?? null;
        if (!$preloaded) $machine = ServerMachine::query()->find($event->machine_id, ['id', 'name', 'is_active', 'token', 'load_status']);
        $result['machine_name'] = (string) ($machine?->name ?? ('#' . $event->machine_id));
        $result['context_current'] = $machine && $machine->is_active
            && hash_equals($event->authority_hash, app(\App\Services\ServerMachine\MachineRiskEventService::class)->authority($machine))
            && $event->probe_revision === app(\App\Services\ServerMachine\MachineEntryProbe::class)->configuration()['revision'];
        $result['investigation'] = $event->investigation?->toArray();
        if ($event->investigation) $result['context_current'] = $result['context_current']
            && $event->investigation->policy_revision === app(MachineRiskSettings::class)->snapshot()['revision'];
        $result['probe_current'] = $machine ? array_intersect_key(app(\App\Services\ServerMachine\MachineTelemetryService::class)->snapshot($machine)['entry_probe'],
            array_flip(['status', 'checked_at', 'valid_until'])) : null;
        if ($detail) {
            $result['uuid_review_available'] = $event->review_verdict !== 'false_positive'
                && ($priority['state'] ?? null) === 'matched' && app(MachineRiskUuidReview::class)->available();
        } else {
            $result['coverage'] = $event->evidence['activity'] ?? [];
            unset($result['evidence'], $result['analysis']);
        }
        return $result;
    }
}
