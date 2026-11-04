<?php

namespace App\Services\ServerMachine;

use App\Models\ServerMachine;
use App\Models\User;
use App\Services\NodeRealtime\NodeRealtimeSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Laravel\Sanctum\PersonalAccessToken;

class MachineSshService
{
    public function __construct(private SshTarget $targets, private SshTransport $transport) {}

    public function cache() { return Cache::store(config('ssh_terminal.cache_store')); }

    public function profile(ServerMachine $machine): array
    {
        $profile = (array) $machine->ssh_profile;
        return ['machine_id' => $machine->id, 'host' => $profile['host'] ?? '',
            'port' => $profile['port'] ?? 22, 'username' => $profile['username'] ?? 'root',
            'fingerprint' => $profile['fingerprint'] ?? null, 'key_type' => $profile['key_type'] ?? null,
            'verified_at' => $profile['verified_at'] ?? null, 'runtime_ready' => $this->available()];
    }

    public function available(): bool
    {
        return (bool) config('ssh_terminal.enabled', false) && function_exists('proc_open') && class_exists(\phpseclib3\Net\SSH2::class);
    }

    public function probe(ServerMachine $machine, int $adminId, array $input): array
    {
        if (!$this->available()) throw new SshTerminalException('unavailable', 503);
        $target = $this->targets->normalize($input);
        $address = $this->targets->resolve($target['host']);
        $identity = $this->transport->probe($target + ['address' => $address]);
        $id = bin2hex(random_bytes(32));
        $this->cache()->put('ssh:probe:' . hash('sha256', $id), [
            'admin_id' => $adminId, 'machine_id' => $machine->id, 'target' => $target,
            'identity' => $identity, 'address' => $address,
        ], 120);
        return ['probe_id' => $id, 'address' => $address, 'expires_in' => 120] + $identity;
    }

    public function confirm(ServerMachine $machine, int $adminId, array $input): array
    {
        $target = $this->targets->normalize($input);
        $key = 'ssh:probe:' . hash('sha256', (string) ($input['probe_id'] ?? ''));
        $record = $this->cache()->lock($key . ':lock', 5)->block(1, fn () => $this->cache()->pull($key));
        if (!$record || $record['admin_id'] !== $adminId || $record['machine_id'] !== $machine->id
            || $record['target'] !== $target || ($input['confirmed'] ?? false) !== true) throw new SshTerminalException('probe_expired', 409);
        $machine->ssh_profile = $target + $record['identity'] + ['verified_at' => time()];
        $machine->save();
        return $this->profile($machine);
    }

    public function socketUrl(): string
    {
        $url = app(NodeRealtimeSettings::class)->resolvedPublicUrl();
        $parts = parse_url($url);
        if (!$parts || ($parts['scheme'] ?? '') !== 'wss' || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new SshTerminalException('secure_websocket_required', 503);
        }
        return $url . '?channel=admin-ssh';
    }

    public function issue(ServerMachine $machine, User $admin, PersonalAccessToken $access, string $origin, array $credentials): array
    {
        if (!$this->available()) throw new SshTerminalException('unavailable', 503);
        $this->authorizeSession(['admin_id' => (int) $admin->id, 'token_id' => (int) $access->id, 'token_hash' => $access->token]);
        $url = $this->socketUrl();
        $profile = (array) $machine->ssh_profile;
        if (empty($profile['fingerprint']) || empty($profile['verified_at'])) throw new SshTerminalException('host_not_verified', 409);
        $target = $this->targets->normalize($profile) + array_intersect_key($profile, array_flip(['fingerprint', 'key_type']));
        $target['address'] = $this->targets->resolve($target['host']);
        $ticket = bin2hex(random_bytes(32));
        $record = ['admin_id' => (int) $admin->id, 'machine_id' => (int) $machine->id,
            'token_id' => (int) $access->id, 'token_hash' => $access->token, 'origin' => $origin,
            'profile_hash' => SshTarget::hash($profile), 'target' => $target, 'credentials' => $credentials,
            'issued_at' => time(), 'lease_owner' => hash('sha256', $ticket)];
        $this->cache()->put($this->ticketKey($ticket), Crypt::encryptString(json_encode($record, JSON_THROW_ON_ERROR)), 45);
        return ['ticket' => $ticket, 'websocket_url' => $url, 'expires_in' => 45,
            'session_seconds' => (int) config('ssh_terminal.session_seconds', 1200)];
    }

    private function ticketKey(string $ticket): string { return 'ssh:ticket:' . hash('sha256', $ticket); }

    public function admit(string $ticket, string $origin): array
    {
        $record = $this->inspect($ticket, $origin);
        if (!$this->cache()->add('ssh:admitted:' . hash('sha256', $ticket), true, 45)) throw new SshTerminalException('ticket_expired', 401);
        return $record;
    }

    public function inspect(string $ticket, string $origin, bool $consume = false): array
    {
        if (!$this->available() || !preg_match('/^[a-f0-9]{64}$/D', $ticket)) throw new SshTerminalException('ticket_expired', 401);
        $key = $this->ticketKey($ticket);
        $encrypted = $consume ? $this->cache()->lock($key . ':lock', 5)->block(1, fn () => $this->cache()->pull($key)) : $this->cache()->get($key);
        try { $record = json_decode(Crypt::decryptString((string) $encrypted), true, 32, JSON_THROW_ON_ERROR); }
        catch (\Throwable) { throw new SshTerminalException('ticket_expired', 401); }
        if ($record['issued_at'] + 45 < time() || !hash_equals($record['origin'], $origin)) throw new SshTerminalException('ticket_expired', 401);
        $this->authorizeSession($record);
        $machine = ServerMachine::find($record['machine_id']);
        if (!$machine || !hash_equals($record['profile_hash'], SshTarget::hash((array) $machine->ssh_profile))) throw new SshTerminalException('profile_changed', 409);
        if (!$this->targets->allowed($record['target']['address'])) throw new SshTerminalException('target_not_allowed');
        return $record;
    }

    public function authorizeSession(array $record): void
    {
        if (!(bool) config('ssh_terminal.enabled', false)) throw new SshTerminalException('session_revoked', 403);
        $token = PersonalAccessToken::find($record['token_id']);
        $user = $token?->tokenable;
        $expiration = (int) config('sanctum.expiration', 0);
        if (!$token || !$user instanceof User || (int) $user->id !== $record['admin_id'] || !$user->is_admin || $user->banned
            || !hash_equals($record['token_hash'], $token->token) || !$token->can('server:ssh')
            || ($token->expires_at && $token->expires_at->isPast())
            || ($expiration > 0 && $token->created_at->lte(now()->subMinutes($expiration)))) {
            throw new SshTerminalException('session_revoked', 403);
        }
    }

    public function acquire(array $record): void
    {
        $key = 'ssh:lease:' . $record['admin_id'];
        $this->cache()->lock($key . ':lock', 5)->block(1, function () use ($key, $record) {
            if (!$this->cache()->add($key, $record['lease_owner'], 1230)) throw new SshTerminalException('session_busy', 409);
        });
    }

    public function release(array $record): void
    {
        $key = 'ssh:lease:' . $record['admin_id'];
        $this->cache()->lock($key . ':lock', 5)->block(1, function () use ($key, $record) {
            if ($this->cache()->get($key) === $record['lease_owner']) $this->cache()->forget($key);
        });
    }
}
