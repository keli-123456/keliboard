<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Server;
use App\Models\ServerMachine;
use App\Models\User;
use App\Services\ServerMachine\MachineEntryProtection;
use Illuminate\Support\Facades\Schema;

class SubscriptionEntryProtectionService
{
    public function forUser(User $user, array $candidateIds, int $responses): array
    {
        $result = ['purpose' => 'reduce_node_entry_exposure', 'mode' => 'observe_only',
            'response_count_lower_bound' => max(0, $responses), 'candidate_nodes_lower_bound' => count($candidateIds),
            'candidate_limit' => 16, 'machine_limit' => 8,
            'status' => 'not_observed', 'machines' => [],
            'boundaries' => ['Successful rendering does not prove client receipt, use, leakage or malicious intent.',
                'Node counters are shared context, never attributable to this subscriber.',
                'Logical machine bindings do not prove which public endpoint the client reached.',
                'No independent public reachability measurement or censorship verdict is available.',
                'Sampling is incomplete; normal network/client changes and high usage are not proof of abuse.']];
        if ($candidateIds === []) return $result;
        if (!is_numeric($user->group_id) || (int) $user->group_id <= 0 || !config('app.key')) {
            $result['status'] = 'unavailable';
            return $result;
        }
        try {
            if (!Schema::hasTable('v2_server') || !Schema::hasTable('v2_server_machine')) {
                $result['status'] = 'unavailable';
                return $result;
            }
            // Revalidate present authorization. No password generation, host resolution or HTTP probe.
            $nodes = Server::query()->whereIn('id', array_slice($candidateIds, 0, 16))->where('enabled', true)->where('show', true)
                ->whereJsonContains('group_ids', (string) $user->group_id)->limit(16)->get(['id', 'machine_id']);
            $ids = $nodes->pluck('machine_id')->filter(fn ($v) => (int) $v > 0)->unique()->take(8)->all();
            $machines = ServerMachine::query()->whereIn('id', $ids)->where('is_active', true)->limit(8)->get(['id', 'load_status']);
            $key = (string) config('app.key');
            $result['machines'] = $machines->map(function ($machine) use ($user, $key): array {
                $context = app(MachineEntryProtection::class)->snapshot((array) $machine->load_status, time());
                unset($context['reachability']['results']);
                return ['ref' => substr(hash_hmac('sha256', $user->id . ':' . $user->token . ':' . $machine->id, $key), 0, 16),
                    'context' => $context];
            })->all();
            $result['status'] = $machines->isEmpty() ? 'unavailable' : 'available';
        } catch (\Throwable) {
            $result['status'] = 'unavailable';
        }
        return $result;
    }
}
