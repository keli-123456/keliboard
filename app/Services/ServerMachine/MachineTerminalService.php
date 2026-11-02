<?php

namespace App\Services\ServerMachine;

use App\Models\ServerMachine;
use App\Models\User;
use App\Services\NodeRealtime\NodeRealtimeSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Laravel\Sanctum\PersonalAccessToken;

class MachineTerminalService
{
    public function cache() { return Cache::store(config('agent_terminal.cache_store')); }
    public function enabled(): bool { return (bool) config('agent_terminal.enabled', false); }

    public function authorizeAdmin(User $user, PersonalAccessToken $access): void
    {
        $token = PersonalAccessToken::find($access->id); $owner = $token?->tokenable;
        $expiration = (int) config('sanctum.expiration', 0);
        if (!$token || !$owner instanceof User || (int) $owner->id !== (int) $user->id || !$owner->is_admin || $owner->banned
            || !$token->can('server:terminal') || !hash_equals($access->token, $token->token)
            || ($token->expires_at && $token->expires_at->isPast())
            || ($expiration > 0 && $token->created_at->lte(now()->subMinutes($expiration)))) throw new SshTerminalException('session_revoked', 403);
    }

    protected function capability(int $machineId): array
    {
        $path = storage_path('app/ws-server/connections.json');
        if (!is_file($path) || time() - filemtime($path) > 45 || filesize($path) > 2097152) return [];
        $snapshot = json_decode((string) file_get_contents($path), true);
        $rows = array_values(array_filter((array) ($snapshot['connections'] ?? []), fn ($row) => (int) ($row['machine_id'] ?? 0) === $machineId));
        foreach ($rows as $row) if (($row['terminal_protocol'] ?? 0) === 1 && ($row['terminal_enabled'] ?? false) === true) return $row;
        return $rows[0] ?? [];
    }

    public function profile(ServerMachine $machine): array
    {
        $agent = $this->capability((int) $machine->id);
        $state = !$agent ? 'offline' : (($agent['terminal_protocol'] ?? 0) !== 1 ? 'upgrade_required'
            : (!(bool) ($agent['terminal_enabled'] ?? false) ? 'local_disabled' : 'ready'));
        if (!$this->enabled()) $state = 'global_disabled';
        return ['machine_id' => $machine->id, 'enabled' => (bool) $machine->terminal_enabled,
            'agent_state' => $state, 'runtime_ready' => $state === 'ready' && (bool) $machine->terminal_enabled && (bool) $machine->is_active,
            'session_seconds' => 1200, 'idle_seconds' => 600, 'cpu_percent' => 10, 'memory_mib' => 64];
    }

    public function socketUrl(): string
    {
        $url = app(NodeRealtimeSettings::class)->resolvedPublicUrl();
        $parts = parse_url($url);
        if (!$parts || ($parts['scheme'] ?? '') !== 'wss' || empty($parts['host']) || isset($parts['user'])
            || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) throw new SshTerminalException('secure_websocket_required', 503);
        return $url . '?channel=admin-terminal';
    }

    public function issue(ServerMachine $machine, User $admin, PersonalAccessToken $token, string $origin): array
    {
        $record = ['admin_id' => (int) $admin->id, 'token_id' => (int) $token->id, 'token_hash' => $token->token,
            'machine_id' => (int) $machine->id, 'machine_token_hash' => hash('sha256', (string) $machine->token),
            'origin' => $origin, 'issued_at' => time()];
        $this->authorize($record);
        if (!$this->profile($machine)['runtime_ready']) throw new SshTerminalException('unavailable', 503);
        $url = $this->socketUrl();
        $ticket = bin2hex(random_bytes(32));
        $this->cache()->put('terminal:ticket:' . hash('sha256', $ticket), Crypt::encryptString(json_encode($record, JSON_THROW_ON_ERROR)), 45);
        return ['ticket' => $ticket, 'websocket_url' => $url, 'expires_in' => 45, 'session_seconds' => 1200];
    }

    public function consume(string $ticket, string $origin): array
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $ticket)) throw new SshTerminalException('ticket_expired', 401);
        $key = 'terminal:ticket:' . hash('sha256', $ticket);
        $record = $this->cache()->lock($key . ':lock', 5)->block(1, function () use ($key, $origin) {
            try { $record = json_decode(Crypt::decryptString((string) $this->cache()->get($key)), true, 16, JSON_THROW_ON_ERROR); }
            catch (\Throwable) { throw new SshTerminalException('ticket_expired', 401); }
            if (!hash_equals($record['origin'], $origin) || $record['issued_at'] + 45 < time()) throw new SshTerminalException('ticket_expired', 401);
            $this->authorize($record);
            $this->cache()->forget($key);
            return $record;
        });
        return $record;
    }

    public function authorize(array $record): void
    {
        if (!$this->enabled()) throw new SshTerminalException('session_revoked', 403);
        $token = PersonalAccessToken::find($record['token_id']);
        $user = $token?->tokenable;
        $expiration = (int) config('sanctum.expiration', 0);
        $machine = ServerMachine::find($record['machine_id']);
        if (!$token || !$user instanceof User || (int) $user->id !== $record['admin_id'] || !$user->is_admin || $user->banned
            || !$token->can('server:terminal') || !hash_equals($record['token_hash'], $token->token)
            || ($token->expires_at && $token->expires_at->isPast())
            || ($expiration > 0 && $token->created_at->lte(now()->subMinutes($expiration)))
            || !$machine || !$machine->is_active || !$machine->terminal_enabled
            || !hash_equals($record['machine_token_hash'], hash('sha256', (string) $machine->token))) {
            throw new SshTerminalException('session_revoked', 403);
        }
    }

    public function authenticateAgent(array $record, array $frame): bool
    {
        $this->authorize($record);
        return (int) ($frame['machine_id'] ?? 0) === $record['machine_id'] && is_string($frame['token'] ?? null)
            && hash_equals($record['machine_token_hash'], hash('sha256', $frame['token']));
    }

    public function acquire(array $record, string $owner): void
    {
        $this->cache()->lock('terminal:leases:lock', 5)->block(1, function () use ($record, $owner) {
            $keys = ['terminal:admin:' . $record['admin_id'], 'terminal:machine:' . $record['machine_id']];
            foreach ($keys as $key) if ($this->cache()->has($key)) throw new SshTerminalException('session_busy', 409);
            foreach ($keys as $key) $this->cache()->put($key, $owner, 1230);
        });
    }

    public function release(array $record, string $owner): void
    {
        $this->cache()->lock('terminal:leases:lock', 5)->block(1, function () use ($record, $owner) {
            foreach (['terminal:admin:' . $record['admin_id'], 'terminal:machine:' . $record['machine_id']] as $key) {
                if ($this->cache()->get($key) === $owner) $this->cache()->forget($key);
            }
        });
    }
}
