<?php

namespace App\Services\Node;

use Illuminate\Http\Request;

class NodeReportSource
{
    public static function installation(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[a-f0-9]{32}$/D', $value) === 1 ? $value : null;
    }

    public static function resolve(Request $request, ?int $authenticatedMachineId = null): array
    {
        $id = self::installation($request->header('X-Keli-Instance-Id'));
        // Machine ownership comes only from successful authentication, never a posted ID or IP.
        $machine = $authenticatedMachineId !== null && $authenticatedMachineId > 0 ? $authenticatedMachineId : null;
        return ['key' => $machine ? 'machine:' . $machine . ($id ? ':' . $id : '') : ($id ? 'agent:' . $id : 'legacy'),
            'machine_id' => $machine, 'installation_id' => $id, 'identified' => $id !== null || $machine !== null];
    }

    public static function from(Request $request): array
    {
        return $request->attributes->get('node_report_source', ['key' => 'legacy', 'machine_id' => null, 'installation_id' => null, 'identified' => false]);
    }

    public static function validKey(?string $key): ?string
    {
        return $key !== null && preg_match('/^(?:machine:[1-9][0-9]{0,18}(?::[a-f0-9]{32})?|agent:[a-f0-9]{32})$/D', $key) === 1 ? $key : null;
    }
}
