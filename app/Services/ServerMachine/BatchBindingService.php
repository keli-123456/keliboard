<?php

namespace App\Services\ServerMachine;

use App\Models\Server;
use App\Models\ServerMachine;
use App\Services\NodeRealtime\NodeRealtimePublisher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class BatchBindingService
{
    public function __construct(private readonly NodeRealtimePublisher $publisher)
    {
    }

    public function normalizePayload(array $payload): array
    {
        $validated = Validator::make($payload, [
            'mode' => 'nullable|in:replace,append',
            'allow_transfer' => 'nullable|boolean',
            'items' => 'required|array|min:1|max:100',
            'items.*.machine_id' => 'required|integer',
            'items.*.node_ids' => 'nullable|array',
            'items.*.node_ids.*' => 'integer',
        ])->validate();

        $items = collect($validated['items'] ?? [])
            ->map(function ($item): ?array {
                if (!is_array($item) || !isset($item['machine_id'])) {
                    return null;
                }
                $machineId = (int) $item['machine_id'];
                if ($machineId <= 0) {
                    return null;
                }

                return [
                    'machine_id' => $machineId,
                    'node_ids' => collect($item['node_ids'] ?? [])
                        ->map(fn ($id): int => (int) $id)
                        ->filter(fn (int $id): bool => $id > 0)
                        ->unique()
                        ->values()
                        ->all(),
                ];
            })
            ->filter()
            ->values()
            ->all();

        if ($items === []) {
            throw new BatchBindingException('请选择需要关联的机器');
        }

        $machineIds = array_column($items, 'machine_id');
        if (count($machineIds) !== count(array_unique($machineIds))) {
            throw new BatchBindingException('同一台机器不能重复提交');
        }

        $nodeOwners = [];
        foreach ($items as $item) {
            foreach ($item['node_ids'] as $nodeId) {
                if (isset($nodeOwners[$nodeId])) {
                    throw new BatchBindingException('同一个节点不能在一次批量关联中分配给多台机器');
                }
                $nodeOwners[$nodeId] = $item['machine_id'];
            }
        }

        $existingMachineIds = ServerMachine::query()
            ->whereIn('id', $machineIds)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
        if (count($existingMachineIds) !== count($machineIds)) {
            throw new BatchBindingException('机器不存在', 400202);
        }

        $nodeIds = array_map('intval', array_keys($nodeOwners));
        if ($nodeIds !== [] && Server::query()->whereIn('id', $nodeIds)->count() !== count($nodeIds)) {
            throw new BatchBindingException('节点不存在', 400202);
        }

        return [
            'mode' => ($validated['mode'] ?? 'replace') === 'append' ? 'append' : 'replace',
            'allow_transfer' => (bool) ($validated['allow_transfer'] ?? false),
            'items' => $items,
        ];
    }

    public function bind(array $payload): array
    {
        $params = $this->normalizePayload($payload);
        $mode = $params['mode'];
        $allowTransfer = $params['allow_transfer'];
        $items = $params['items'];
        $machineIds = array_column($items, 'machine_id');
        $requestedNodeIds = collect($items)->pluck('node_ids')->flatten()->unique()->values()->all();
        $nodes = $requestedNodeIds === []
            ? collect()
            : Server::query()->whereIn('id', $requestedNodeIds)->get(['id', 'machine_id'])->keyBy('id');

        $result = DB::transaction(function () use ($items, $machineIds, $nodes, $mode, $allowTransfer): array {
            $summary = [
                'machines' => count($items),
                'bound' => 0,
                'unbound' => 0,
                'transferred' => 0,
                'skipped' => 0,
            ];
            $results = [];
            $affectedMachineIds = $machineIds;
            $affectedServerIds = [];
            $currentMachineByNode = $nodes
                ->mapWithKeys(fn ($node, $id): array => [(int) $id => $node->machine_id === null ? null : (int) $node->machine_id])
                ->all();
            $oldNodeIdsByMachine = Server::query()
                ->whereIn('machine_id', $machineIds)
                ->get(['id', 'machine_id'])
                ->groupBy(fn ($node): int => (int) $node->machine_id)
                ->map(fn ($rows): array => $rows->pluck('id')->map(fn ($id): int => (int) $id)->all())
                ->all();

            foreach ($items as $item) {
                $machineId = (int) $item['machine_id'];
                $requestedIds = $item['node_ids'];
                $bindableIds = [];
                $skippedIds = [];
                $transferredIds = [];
                $newlyBoundIds = [];

                foreach ($requestedIds as $nodeId) {
                    $currentMachineId = $currentMachineByNode[$nodeId] ?? null;
                    if ($currentMachineId === null || $currentMachineId === $machineId || $allowTransfer) {
                        $bindableIds[] = $nodeId;
                        if ($currentMachineId !== null && $currentMachineId !== $machineId) {
                            $transferredIds[] = $nodeId;
                            $affectedMachineIds[] = $currentMachineId;
                        }
                        if ($currentMachineId !== $machineId) {
                            $newlyBoundIds[] = $nodeId;
                        }
                    } else {
                        $skippedIds[] = $nodeId;
                    }
                }

                $oldNodeIds = $oldNodeIdsByMachine[$machineId] ?? [];
                $unboundIds = [];
                if ($mode === 'replace') {
                    $unboundIds = array_values(array_diff($oldNodeIds, $bindableIds));
                    if ($unboundIds !== []) {
                        Server::query()->whereIn('id', $unboundIds)->update(['machine_id' => null]);
                    }
                }
                if ($bindableIds !== []) {
                    Server::query()->whereIn('id', $bindableIds)->update(['machine_id' => $machineId]);
                }

                $summary['bound'] += count($newlyBoundIds);
                $summary['unbound'] += count($unboundIds);
                $summary['transferred'] += count($transferredIds);
                $summary['skipped'] += count($skippedIds);
                $affectedServerIds = array_merge($affectedServerIds, $requestedIds, $unboundIds);
                $results[] = [
                    'machine_id' => $machineId,
                    'requested_node_ids' => $requestedIds,
                    'bound_node_ids' => $bindableIds,
                    'skipped_node_ids' => $skippedIds,
                    'unbound_node_ids' => $unboundIds,
                    'transferred_node_ids' => $transferredIds,
                ];
            }

            return [
                'mode' => $mode,
                'allow_transfer' => $allowTransfer,
                'summary' => $summary,
                'items' => $results,
                'affected_machine_ids' => array_values(array_unique(array_filter(array_map('intval', $affectedMachineIds)))),
                'affected_server_ids' => array_values(array_unique(array_filter(array_map('intval', $affectedServerIds)))),
            ];
        });

        $this->publisher->invalidateConfigForMachines(
            $result['affected_machine_ids'],
            'admin.server_machine.batch_bound',
            [
                'server_ids' => $result['affected_server_ids'],
                'mode' => $mode,
                'allow_transfer' => $allowTransfer,
            ]
        );
        unset($result['affected_machine_ids'], $result['affected_server_ids']);

        return $result;
    }
}
