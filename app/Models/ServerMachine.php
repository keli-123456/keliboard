<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ServerMachine extends Model
{
    protected $table = 'v2_server_machine';

    protected $guarded = ['id'];

    protected $casts = [
        'is_active' => 'boolean',
        'subproxy_enabled' => 'boolean',
        'webproxy_enabled' => 'boolean',
        'webproxy_site_domain_id' => 'integer',
        'subproxy_https_port' => 'integer',
        'subproxy_http_port' => 'integer',
        'subproxy_cert_state' => 'array',
        'last_seen_at' => 'integer',
        'load_status' => 'array',
        'upgrade_state' => 'array',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];

    public static function generateToken(): string
    {
        return bin2hex(random_bytes(32));
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
