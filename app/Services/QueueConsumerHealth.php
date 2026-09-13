<?php

namespace App\Services;

use Illuminate\Support\Str;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\Contracts\SupervisorRepository;
use Laravel\Horizon\MasterSupervisor;
use Laravel\Horizon\ProvisioningPlan;

final class QueueConsumerHealth
{
    public function snapshot(bool $local = false): array
    {
        $environment = (string) (config('horizon.env') ?? config('app.env'));
        $plan = collect(ProvisioningPlan::get('health-check')->parsed)
            ->first(fn ($value, $name) => Str::is($name, $environment), []);
        $expected = [];
        foreach ($plan as $options) {
            if ($options->maxProcesses > 0) {
                foreach (explode(',', $options->queue) as $queue) {
                    $expected[] = $options->connection . ':' . trim($queue);
                }
            }
        }
        $expected = array_values(array_unique($expected));
        $masters = collect(app(MasterSupervisorRepository::class)->all())
            ->filter(fn ($master) => !$local || str_starts_with($master->name, MasterSupervisor::basename() . '-'));
        $activeNames = $masters->filter(fn ($master) => $master->status === 'running')
            ->flatMap(fn ($master) => $master->supervisors ?? [])->all();
        $consumers = [];
        $processes = 0;
        foreach (app(SupervisorRepository::class)->all() as $supervisor) {
            if ($supervisor->status !== 'running' || !in_array($supervisor->name, $activeNames, true)) {
                continue;
            }
            foreach ((array) $supervisor->processes as $key => $count) {
                if ((int) $count <= 0) {
                    continue;
                }
                $processes += (int) $count;
                [$connection, $queues] = array_pad(explode(':', $key, 2), 2, '');
                foreach (explode(',', $queues) as $queue) {
                    $consumers[$connection . ':' . trim($queue)] = true;
                }
            }
        }
        $missing = array_values(array_diff($expected, array_keys($consumers)));
        $paused = $masters->filter(fn ($master) => $master->status === 'paused')->count();
        return [
            'healthy' => $expected !== [] && $missing === [] && $paused === 0,
            'environment' => $environment, 'processes' => $processes, 'paused_masters' => $paused,
            'expected_queues' => $expected, 'missing_queues' => $missing,
        ];
    }
}
