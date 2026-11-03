<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Models\MachineRiskEvent;
use App\Models\ServerMachine;
use App\Services\ServerMachine\MachineRiskAnalysis;
use App\Services\ServerMachine\MachineRiskSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MachineRiskController extends Controller
{
    public function overview(Request $request, MachineRiskSettings $policy): JsonResponse
    {
        $data = $request->validate(['machine_id' => ['nullable', 'integer', 'min:1'], 'status' => ['nullable', 'in:open,recovered']]);
        $snapshot = $policy->snapshot();
        $events = []; $summary = ['open' => 0, 'recovered' => 0, 'analyses_last_hour' => 0];
        if ($snapshot['available']) {
            $query = MachineRiskEvent::query()->when(isset($data['machine_id']), fn ($q) => $q->where('machine_id', $data['machine_id']));
            $summary['open'] = (clone $query)->where('status', 'open')->count();
            $summary['recovered'] = (clone $query)->where('status', 'recovered')->count();
            $summary['analyses_last_hour'] = DB::table('v2_machine_risk_ai_attempt')->where('created_at', '>=', time() - 3600)->count();
            $rows = $query->when(isset($data['status']), fn ($q) => $q->where('status', $data['status']))
                ->orderByDesc('detected_at')->limit(50)->get();
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
        return $this->success(['available' => $snapshot['available'], 'revision' => $snapshot['revision'],
            'settings' => $policy->settings(), 'summary' => $summary, 'events' => $events,
            'ai_ready' => (bool) $ready, 'ai_model' => $model, 'retention_days' => 7]);
    }

    public function detail(int $id): JsonResponse
    {
        abort_unless(app(MachineRiskSettings::class)->available(), 409, 'migration_required');
        return $this->success($this->payload(MachineRiskEvent::query()->findOrFail($id), true));
    }

    public function saveSettings(Request $request, MachineRiskSettings $policy): JsonResponse
    {
        $data = $request->validate(['enabled' => ['required', 'boolean'], 'auto_ai' => ['required', 'boolean'],
            'notify_admin' => ['required', 'boolean'], 'hourly_budget' => ['required', 'integer', 'min:1', 'max:10'],
            'expected_revision' => ['required', 'integer', 'min:1'], 'confirmed' => ['required', 'boolean']]);
        try {
            $settings = array_intersect_key($data, MachineRiskSettings::DEFAULTS);
            foreach (['enabled', 'auto_ai', 'notify_admin'] as $key) $settings[$key] = (bool) $settings[$key];
            $settings['hourly_budget'] = (int) $settings['hourly_budget'];
            return $this->success($policy->save($settings,
                $data['expected_revision'], (int) $request->user()->id, (bool) $data['confirmed']));
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
        $event = MachineRiskEvent::query()->findOrFail($id);
        $event->forceFill(['review_verdict' => $data['verdict'], 'reviewed_by' => (int) $request->user()->id, 'reviewed_at' => time()])->save();
        return $this->success($this->payload($event, true));
    }

    private function payload(MachineRiskEvent $event, bool $detail, ?ServerMachine $machine = null, bool $preloaded = false): array
    {
        $result = $event->toArray();
        if (!$preloaded) $machine = ServerMachine::query()->find($event->machine_id, ['id', 'name', 'is_active', 'token', 'load_status']);
        $result['machine_name'] = (string) ($machine?->name ?? ('#' . $event->machine_id));
        $result['context_current'] = $machine && $machine->is_active
            && hash_equals($event->authority_hash, app(\App\Services\ServerMachine\MachineRiskEventService::class)->authority($machine))
            && $event->probe_revision === app(\App\Services\ServerMachine\MachineEntryProbe::class)->configuration()['revision'];
        $result['probe_current'] = $machine ? array_intersect_key(app(\App\Services\ServerMachine\MachineTelemetryService::class)->snapshot($machine)['entry_probe'],
            array_flip(['status', 'checked_at', 'valid_until'])) : null;
        if (!$detail) {
            $result['coverage'] = $event->evidence['activity'] ?? [];
            unset($result['evidence'], $result['analysis']);
        }
        return $result;
    }
}
