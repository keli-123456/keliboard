<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ServerMachine extends Model
{
    protected static function booted(): void
    {
        static::deleting(function (ServerMachine $machine): void {
            if (\App\Services\Node\NodeMachineBinding::available()) \Illuminate\Support\Facades\DB::table('v2_server_machine_binding')->where('machine_id', $machine->id)->delete();
        });
    }

    protected $table = 'v2_server_machine';

    protected $guarded = ['id'];

    protected $hidden = ['ssh_profile'];

    protected $casts = [
        'terminal_enabled' => 'boolean',
        'is_active' => 'boolean',
        'subproxy_enabled' => 'boolean',
        'webproxy_enabled' => 'boolean',
        'webproxy_site_domain_id' => 'integer',
        'webproxy_bindings' => 'array',
        'subproxy_https_port' => 'integer',
        'subproxy_http_port' => 'integer',
        'subproxy_cert_state' => 'array',
        'last_seen_at' => 'integer',
        'load_status' => 'array',
        'upgrade_state' => 'array',
        'ssh_profile' => 'array',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];

    public static function generateToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    public function getUpgradeStateAttribute(?string $value): ?array
    {
        $state = $this->fromJson($value);
        return is_array($state) ? ($state['instance_upgrade'] ?? $state) : null;
    }

    public function setUpgradeStateAttribute(?array $state): void
    {
        // Legacy panel workers cannot dispatch a targeted command, even after rollback.
        if (isset($state['target_installation_id'])) $state = ['instance_upgrade' => $state];
        $this->attributes['upgrade_state'] = $state === null ? null : $this->castAttributeAsJson('upgrade_state', $state);
    }

    public function servers(): HasMany
    {
        return $this->hasMany(Server::class, 'machine_id', 'id');
    }

    public function loadHistory(): HasMany
    {
        return $this->hasMany(ServerMachineLoadHistory::class, 'machine_id', 'id');
    }
}
