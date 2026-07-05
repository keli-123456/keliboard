<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServerTlsCertificate extends Model
{
    protected $table = 'v2_server_tls_certificate';

    protected $guarded = ['id'];

    protected $casts = [
        'server_id' => 'integer',
        'machine_id' => 'integer',
        'changed_at' => 'timestamp',
        'reported_at' => 'timestamp',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class, 'server_id', 'id');
    }

    public function machine(): BelongsTo
    {
        return $this->belongsTo(ServerMachine::class, 'machine_id', 'id');
    }
}
