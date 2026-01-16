<?php

namespace Plugin\Cloudmanger\Controllers;

use App\Helpers\ResponseEnum;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WorkerConfigController extends Controller
{
    private const TABLE = 'cm_worker_configs';
    private const TOKEN_NAME_PREFIX = 'cm-worker:';

    public function listForUser(int $userId)
    {
        if (!$this->userExists($userId)) {
            return $this->fail(ResponseEnum::CLIENT_PARAMETER_ERROR, null, 'User not found');
        }

        $rows = DB::table(self::TABLE)
            ->where('user_id', $userId)
            ->orderBy('worker')
            ->get(['worker', 'note', 'updated_at', 'created_at']);

        return $this->success([
            'user_id' => $userId,
            'configs' => $rows,
        ]);
    }

    public function getForUser(int $userId, string $worker, Request $request)
    {
        $worker = $this->normalizeWorker($worker);
        if ($worker === null) {
            return $this->fail(ResponseEnum::CLIENT_PARAMETER_ERROR, null, 'Invalid worker');
        }
        if (!$this->userExists($userId)) {
            return $this->fail(ResponseEnum::CLIENT_PARAMETER_ERROR, null, 'User not found');
        }

        $row = DB::table(self::TABLE)
            ->where('user_id', $userId)
            ->where('worker', $worker)
            ->first();

        if (!$row) {
            return $this->fail(ResponseEnum::CLIENT_NOT_FOUND_ERROR, null, 'Config not found');
        }

        $include = $request->query('include_config');
        $includeConfig = $include === null ? true : ($include === '1' || $include === 1 || $include === true || $include === 'true');

        $data = [
            'user_id' => $userId,
            'worker' => $worker,
            'note' => $row->note,
            'updated_at' => $row->updated_at,
            'created_at' => $row->created_at,
        ];

        if ($includeConfig) {
            $config = $this->decryptConfig((string) $row->config_encrypted);
            if ($config === null) {
                return $this->fail(ResponseEnum::SYSTEM_ERROR, null, 'Failed to decrypt config');
            }
            $data['config'] = $config;
        }

        return $this->success($data);
    }

    public function upsertForUser(int $userId, string $worker, Request $request)
    {
        $worker = $this->normalizeWorker($worker);
        if ($worker === null) {
            return $this->fail(ResponseEnum::CLIENT_PARAMETER_ERROR, null, 'Invalid worker');
        }
        if (!$this->userExists($userId)) {
            return $this->fail(ResponseEnum::CLIENT_PARAMETER_ERROR, null, 'User not found');
        }

        $params = $request->validate([
            'config' => ['required'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $config = $this->parseConfig($params['config']);
        if ($config === null) {
            return $this->fail(ResponseEnum::CLIENT_PARAMETER_ERROR, null, 'Invalid config');
        }

        $encrypted = $this->encryptConfig($config);
        if ($encrypted === null) {
            return $this->fail(ResponseEnum::SYSTEM_ERROR, null, 'Failed to encrypt config');
        }

        $now = now();
        $existing = DB::table(self::TABLE)
            ->where('user_id', $userId)
            ->where('worker', $worker)
            ->first(['id']);

        if ($existing) {
            DB::table(self::TABLE)
                ->where('id', $existing->id)
                ->update([
                    'config_encrypted' => $encrypted,
                    'note' => $params['note'] ?? null,
                    'updated_at' => $now,
                ]);
        } else {
            DB::table(self::TABLE)
                ->insert([
                    'user_id' => $userId,
                    'worker' => $worker,
                    'config_encrypted' => $encrypted,
                    'note' => $params['note'] ?? null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
        }

        return $this->success([
            'user_id' => $userId,
            'worker' => $worker,
        ]);
    }

    public function deleteForUser(int $userId, string $worker)
    {
        $worker = $this->normalizeWorker($worker);
        if ($worker === null) {
            return $this->fail(ResponseEnum::CLIENT_PARAMETER_ERROR, null, 'Invalid worker');
        }
        if (!$this->userExists($userId)) {
            return $this->fail(ResponseEnum::CLIENT_PARAMETER_ERROR, null, 'User not found');
        }

        DB::table(self::TABLE)
            ->where('user_id', $userId)
            ->where('worker', $worker)
            ->delete();

        return $this->success([
            'user_id' => $userId,
            'worker' => $worker,
        ]);
    }

    public function rendered(string $worker, Request $request)
    {
        $worker = $this->normalizeWorker($worker);
        if ($worker === null) {
            return response()->json(['message' => 'Invalid worker'], 400);
        }

        $user = Auth::guard()->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $accessToken = method_exists($user, 'currentAccessToken') ? $user->currentAccessToken() : null;
        if (!$accessToken) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $name = (string) ($accessToken->name ?? '');
        if (!Str::startsWith($name, self::TOKEN_NAME_PREFIX)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $tokenWorker = substr($name, strlen(self::TOKEN_NAME_PREFIX));
        if ($tokenWorker !== '*' && $tokenWorker !== $worker) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $row = DB::table(self::TABLE)
            ->where('user_id', $user->id)
            ->where('worker', $worker)
            ->first();

        if (!$row) {
            return response()->json(['message' => 'Config not found'], 404);
        }

        $config = $this->decryptConfig((string) $row->config_encrypted);
        if ($config === null) {
            return response()->json(['message' => 'Config decrypt failed'], 500);
        }

        return response()->json($config);
    }

    private function normalizeWorker(string $worker): ?string
    {
        $worker = trim($worker);
        if ($worker === '') return null;
        if (strlen($worker) > 64) return null;
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $worker)) return null;
        return $worker;
    }

    private function userExists(int $userId): bool
    {
        return User::query()
            ->where('id', $userId)
            ->exists();
    }

    private function parseConfig($input): ?array
    {
        if (is_array($input)) {
            return $input;
        }
        if (is_string($input)) {
            $decoded = json_decode($input, true);
            return is_array($decoded) ? $decoded : null;
        }
        return null;
    }

    private function encryptConfig(array $config): ?string
    {
        try {
            $json = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($json === false) return null;
            return Crypt::encryptString($json);
        } catch (\Throwable) {
            return null;
        }
    }

    private function decryptConfig(string $encrypted): ?array
    {
        try {
            $json = Crypt::decryptString($encrypted);
            $decoded = json_decode($json, true);
            return is_array($decoded) ? $decoded : null;
        } catch (\Throwable) {
            return null;
        }
    }
}

