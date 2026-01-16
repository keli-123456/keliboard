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

class WorkerScriptController extends Controller
{
    private const TABLE = 'cm_worker_scripts';
    private const TOKEN_NAME_PREFIX = 'cm-worker:';

    public function listForUser(int $userId, string $worker)
    {
        $worker = $this->normalizeWorker($worker);
        if ($worker === null) {
            return $this->fail(ResponseEnum::CLIENT_PARAMETER_ERROR, null, 'Invalid worker');
        }
        if (!$this->userExists($userId)) {
            return $this->fail(ResponseEnum::CLIENT_PARAMETER_ERROR, null, 'User not found');
        }

        $rows = DB::table(self::TABLE)
            ->where('user_id', $userId)
            ->where('worker', $worker)
            ->orderBy('script_id')
            ->get(['script_id', 'note', 'updated_at', 'created_at']);

        return $this->success([
            'user_id' => $userId,
            'worker' => $worker,
            'scripts' => $rows,
        ]);
    }

    public function getForUser(int $userId, string $worker, string $scriptId)
    {
        $worker = $this->normalizeWorker($worker);
        if ($worker === null) {
            return $this->fail(ResponseEnum::CLIENT_PARAMETER_ERROR, null, 'Invalid worker');
        }
        $scriptId = $this->normalizeScriptId($scriptId);
        if ($scriptId === null) {
            return $this->fail(ResponseEnum::CLIENT_PARAMETER_ERROR, null, 'Invalid script_id');
        }
        if (!$this->userExists($userId)) {
            return $this->fail(ResponseEnum::CLIENT_PARAMETER_ERROR, null, 'User not found');
        }

        $row = DB::table(self::TABLE)
            ->where('user_id', $userId)
            ->where('worker', $worker)
            ->where('script_id', $scriptId)
            ->first();

        if (!$row) {
            return $this->fail(ResponseEnum::CLIENT_NOT_FOUND_ERROR, null, 'Script not found');
        }

        $content = $this->decryptContent((string) $row->content_encrypted);
        if ($content === null) {
            return $this->fail(ResponseEnum::SYSTEM_ERROR, null, 'Failed to decrypt script');
        }

        return $this->success([
            'user_id' => $userId,
            'worker' => $worker,
            'script_id' => $scriptId,
            'note' => $row->note,
            'content' => $content,
            'updated_at' => $row->updated_at,
            'created_at' => $row->created_at,
        ]);
    }

    public function upsertForUser(int $userId, string $worker, string $scriptId, Request $request)
    {
        $worker = $this->normalizeWorker($worker);
        if ($worker === null) {
            return $this->fail(ResponseEnum::CLIENT_PARAMETER_ERROR, null, 'Invalid worker');
        }
        $scriptId = $this->normalizeScriptId($scriptId);
        if ($scriptId === null) {
            return $this->fail(ResponseEnum::CLIENT_PARAMETER_ERROR, null, 'Invalid script_id');
        }
        if (!$this->userExists($userId)) {
            return $this->fail(ResponseEnum::CLIENT_PARAMETER_ERROR, null, 'User not found');
        }

        $params = $request->validate([
            'content' => ['required', 'string'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $encrypted = $this->encryptContent((string) $params['content']);
        if ($encrypted === null) {
            return $this->fail(ResponseEnum::SYSTEM_ERROR, null, 'Failed to encrypt script');
        }

        $now = now();
        $existing = DB::table(self::TABLE)
            ->where('user_id', $userId)
            ->where('worker', $worker)
            ->where('script_id', $scriptId)
            ->first(['id']);

        if ($existing) {
            DB::table(self::TABLE)
                ->where('id', $existing->id)
                ->update([
                    'content_encrypted' => $encrypted,
                    'note' => $params['note'] ?? null,
                    'updated_at' => $now,
                ]);
        } else {
            DB::table(self::TABLE)
                ->insert([
                    'user_id' => $userId,
                    'worker' => $worker,
                    'script_id' => $scriptId,
                    'content_encrypted' => $encrypted,
                    'note' => $params['note'] ?? null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
        }

        return $this->success([
            'user_id' => $userId,
            'worker' => $worker,
            'script_id' => $scriptId,
        ]);
    }

    public function deleteForUser(int $userId, string $worker, string $scriptId)
    {
        $worker = $this->normalizeWorker($worker);
        if ($worker === null) {
            return $this->fail(ResponseEnum::CLIENT_PARAMETER_ERROR, null, 'Invalid worker');
        }
        $scriptId = $this->normalizeScriptId($scriptId);
        if ($scriptId === null) {
            return $this->fail(ResponseEnum::CLIENT_PARAMETER_ERROR, null, 'Invalid script_id');
        }
        if (!$this->userExists($userId)) {
            return $this->fail(ResponseEnum::CLIENT_PARAMETER_ERROR, null, 'User not found');
        }

        DB::table(self::TABLE)
            ->where('user_id', $userId)
            ->where('worker', $worker)
            ->where('script_id', $scriptId)
            ->delete();

        return $this->success([
            'user_id' => $userId,
            'worker' => $worker,
            'script_id' => $scriptId,
        ]);
    }

    public function bundle(string $worker)
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

        $rows = DB::table(self::TABLE)
            ->where('user_id', $user->id)
            ->where('worker', $worker)
            ->orderBy('script_id')
            ->get(['script_id', 'content_encrypted']);

        $scripts = [];
        foreach ($rows as $row) {
            $content = $this->decryptContent((string) $row->content_encrypted);
            if ($content === null) {
                continue;
            }
            $scripts[(string) $row->script_id] = $content;
        }

        return response()->json([
            'worker' => $worker,
            'scripts' => $scripts,
        ]);
    }

    public function fetch(string $worker, string $scriptId)
    {
        $worker = $this->normalizeWorker($worker);
        if ($worker === null) {
            return response()->json(['message' => 'Invalid worker'], 400);
        }
        $scriptId = $this->normalizeScriptId($scriptId);
        if ($scriptId === null) {
            return response()->json(['message' => 'Invalid script_id'], 400);
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
            ->where('script_id', $scriptId)
            ->first(['script_id', 'content_encrypted', 'note', 'updated_at']);

        if (!$row) {
            return response()->json(['message' => 'Script not found'], 404);
        }

        $content = $this->decryptContent((string) $row->content_encrypted);
        if ($content === null) {
            return response()->json(['message' => 'Script decrypt failed'], 500);
        }

        return response()->json([
            'worker' => $worker,
            'script_id' => $scriptId,
            'note' => $row->note,
            'updated_at' => $row->updated_at,
            'content' => $content,
        ]);
    }

    private function normalizeWorker(string $worker): ?string
    {
        $worker = trim($worker);
        if ($worker === '') return null;
        if (strlen($worker) > 64) return null;
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $worker)) return null;
        return $worker;
    }

    private function normalizeScriptId(string $scriptId): ?string
    {
        $scriptId = trim($scriptId);
        if ($scriptId === '') return null;
        if (strlen($scriptId) > 128) return null;
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $scriptId)) return null;
        return $scriptId;
    }

    private function userExists(int $userId): bool
    {
        return User::query()
            ->where('id', $userId)
            ->exists();
    }

    private function encryptContent(string $content): ?string
    {
        try {
            return Crypt::encryptString($content);
        } catch (\Throwable) {
            return null;
        }
    }

    private function decryptContent(string $encrypted): ?string
    {
        try {
            return Crypt::decryptString($encrypted);
        } catch (\Throwable) {
            return null;
        }
    }
}

