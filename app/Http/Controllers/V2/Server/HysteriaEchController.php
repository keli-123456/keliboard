<?php

declare(strict_types=1);

namespace App\Http\Controllers\V2\Server;

use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Services\Node\HysteriaEchProvisioningService;
use Illuminate\Http\Request;
use InvalidArgumentException;

final class HysteriaEchController extends Controller
{
    public function report(Request $request, HysteriaEchProvisioningService $service)
    {
        $node = $request->attributes->get('node_info');
        if (!$node instanceof Server || $node->type !== 'hysteria') return $this->fail([403, 'HY2 node authorization required']);
        if (strlen($request->getContent()) > 24576) {
            return $this->fail([422, 'Only public ECH metadata is accepted']);
        }
        // Authentication middleware merges normalized node_type into the JSON input bag.
        // Check the original body instead; it may contain only the four public fields.
        try {
            $payload = json_decode($request->getContent(), true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->fail([422, 'Invalid public ECH metadata']);
        }
        if (!is_array($payload)
            || array_diff(array_keys($payload), ['request_id', 'phase', 'config', 'key_file'])) {
            return $this->fail([422, 'Only public ECH metadata is accepted']);
        }
        $report = validator($payload, [
            'request_id' => ['required', 'string', 'regex:/^[a-f0-9]{32}$/D'],
            'phase' => 'required|in:prepared,ready', 'config' => 'required|string|max:16384',
            'key_file' => 'required|string|max:4096',
        ])->validate();
        try {
            return $this->success($service->report($node, $report));
        } catch (InvalidArgumentException $error) {
            return $this->fail([409, $error->getMessage()]);
        }
    }
}
