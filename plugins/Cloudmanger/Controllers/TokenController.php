<?php

namespace Plugin\Cloudmanger\Controllers;

use App\Helpers\ResponseEnum;
use App\Http\Controllers\Controller;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TokenController extends Controller
{
    private const TOKEN_NAME_PREFIX = 'cm-worker:';

    public function listForUser(int $userId)
    {
        if (!$this->userExists($userId)) {
            return $this->fail(ResponseEnum::CLIENT_PARAMETER_ERROR, null, 'User not found');
        }

        $tokens = DB::table('personal_access_tokens')
            ->where('tokenable_type', User::class)
            ->where('tokenable_id', $userId)
            ->where('name', 'like', self::TOKEN_NAME_PREFIX . '%')
            ->orderByDesc('id')
            ->get(['id', 'name', 'abilities', 'last_used_at', 'expires_at', 'created_at']);

        return $this->success([
            'user_id' => $userId,
            'tokens' => $tokens,
        ]);
    }

    public function createForUser(int $userId, Request $request)
    {
        $user = User::query()->where('id', $userId)->first();
        if (!$user) {
            return $this->fail(ResponseEnum::CLIENT_PARAMETER_ERROR, null, 'User not found');
        }

        $params = $request->validate([
            'worker' => ['nullable', 'string', 'max:64'],
            'expires_at' => ['nullable', 'integer', 'min:0'],
        ]);

        $worker = isset($params['worker']) && $params['worker'] !== null ? trim((string) $params['worker']) : '*';
        if ($worker === '') $worker = '*';

        if ($worker !== '*' && !preg_match('/^[a-zA-Z0-9_-]+$/', $worker)) {
            return $this->fail(ResponseEnum::CLIENT_PARAMETER_ERROR, null, 'Invalid worker');
        }

        $expiresAt = null;
        if (isset($params['expires_at']) && (int) $params['expires_at'] > 0) {
            $expiresAt = Carbon::createFromTimestamp((int) $params['expires_at']);
        }

        $tokenName = self::TOKEN_NAME_PREFIX . $worker;
        $newToken = $user->createToken($tokenName, ['cm-worker'], $expiresAt);
        $tokenParts = explode('|', $newToken->plainTextToken);
        $tokenRaw = $tokenParts[1] ?? $tokenParts[0];

        return $this->success([
            'auth_data' => 'Bearer ' . $tokenRaw,
            'token' => $tokenRaw,
            'token_id' => $newToken->accessToken->id ?? null,
            'name' => $newToken->accessToken->name ?? $tokenName,
            'expires_at' => $newToken->accessToken->expires_at ?? null,
        ]);
    }

    public function revokeForUser(int $userId, int $tokenId)
    {
        if (!$this->userExists($userId)) {
            return $this->fail(ResponseEnum::CLIENT_PARAMETER_ERROR, null, 'User not found');
        }

        $deleted = DB::table('personal_access_tokens')
            ->where('id', $tokenId)
            ->where('tokenable_type', User::class)
            ->where('tokenable_id', $userId)
            ->where('name', 'like', self::TOKEN_NAME_PREFIX . '%')
            ->delete();

        if (!$deleted) {
            return $this->fail(ResponseEnum::CLIENT_NOT_FOUND_ERROR, null, 'Token not found');
        }

        return $this->success([
            'user_id' => $userId,
            'token_id' => $tokenId,
        ]);
    }

    private function userExists(int $userId): bool
    {
        return User::query()
            ->where('id', $userId)
            ->exists();
    }
}
