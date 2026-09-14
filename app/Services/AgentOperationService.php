<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class AgentOperationService
{
    public function execute(User $agent, ?string $key, string $action, array $payload, callable $operation): array
    {
        // Older themes remain compatible; new themes supply a key for every paid operation.
        if ($key === null) {
            return $operation();
        }
        if (!preg_match('/\A[a-zA-Z0-9_-]{16,64}\z/', $key)) {
            throw new ApiException(__('Invalid operation request key'));
        }
        $fingerprint = hash_hmac('sha256', json_encode(
            [$action, $this->canonicalPayload($payload)], JSON_THROW_ON_ERROR
        ), (string) config('app.key'));

        return DB::transaction(function () use ($agent, $key, $fingerprint, $operation): array {
            // Serializes concurrent retries with balance mutations for this agent.
            User::query()->lockForUpdate()->findOrFail($agent->id);
            $query = DB::table('v2_agent_operation')->where('agent_user_id', $agent->id)->where('request_key', $key);
            $previous = $query->first();
            if ($previous) {
                if (!hash_equals($previous->fingerprint, $fingerprint)) {
                    throw new ApiException(__('Operation request key was already used for different data'));
                }
                return json_decode($previous->result, true, 512, JSON_THROW_ON_ERROR);
            }
            $result = $operation();
            DB::table('v2_agent_operation')->insert([
                'agent_user_id' => $agent->id,
                'request_key' => $key,
                'fingerprint' => $fingerprint,
                'result' => json_encode($result, JSON_THROW_ON_ERROR),
                'created_at' => time(),
            ]);
            return $result;
        });
    }

    private function canonicalPayload(array $payload): array
    {
        ksort($payload);
        foreach ($payload as &$value) {
            if (is_array($value)) {
                $value = $this->canonicalPayload($value);
            }
        }
        return $payload;
    }
}
