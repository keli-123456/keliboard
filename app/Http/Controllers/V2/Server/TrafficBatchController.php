<?php

declare(strict_types=1);

namespace App\Http\Controllers\V2\Server;

use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Services\TrafficBatchPayload;
use App\Services\TrafficBatchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TrafficBatchController extends Controller
{
    public function capability(Request $request): JsonResponse
    {
        $node = $request->attributes->get('node_info');
        abort_unless($node instanceof Server, 403);
        abort_unless(\Illuminate\Support\Facades\Schema::hasTable('v2_traffic_batch'), 503);
        return response()->json([
            'version' => 1, 'node_id' => (int) $node->id, 'node_type' => $node->type,
            'max_users' => TrafficBatchPayload::MAX_USERS,
            'max_body_bytes' => TrafficBatchPayload::MAX_BODY_BYTES,
            'durable_receipts' => true,
        ])->header('Cache-Control', 'no-store');
    }

    public function report(Request $request, TrafficBatchService $service): JsonResponse
    {
        $node = $request->attributes->get('node_info');
        abort_unless($node instanceof Server, 403);
        abort_if(strlen($request->getContent()) > TrafficBatchPayload::MAX_BODY_BYTES, 413);
        $body = $request->json()->all();
        if (($body['version'] ?? null) !== 1 || array_diff(array_keys($body), [
            'version', 'report_id', 'traffic', 'token', 'node_id', 'node_type', 'machine_id',
        ])) {
            return response()->json(['message' => 'Unsupported traffic batch schema.'], 422);
        }
        try {
            $receipt = $service->accept($node, $body['report_id'] ?? null, $body['traffic'] ?? null);
        } catch (\InvalidArgumentException | \OverflowException $error) {
            return response()->json(['message' => $error->getMessage()], 422);
        }
        $service->dispatch($receipt);
        // Only a previously committed accounting transaction earns an applied receipt.
        return response()->json([
            'version' => 1, 'report_id' => $receipt->report_id, 'content_hash' => $receipt->content_hash,
            'node_id' => (int) $receipt->node_id, 'node_type' => $receipt->node_type,
            'status' => $receipt->processed_at === null ? 'pending' : 'applied',
        ], $receipt->processed_at === null ? 202 : 200);
    }
}
