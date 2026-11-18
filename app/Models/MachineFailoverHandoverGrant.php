<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class MachineFailoverHandoverGrant extends Model
{
    public const TABLE = 'v2_machine_failover_handover_grant';

    protected $table = self::TABLE;
    protected $guarded = [];
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    protected $hidden = ['context_hash', 'evidence_sha256', 'release', 'stop_receipt', 'closure_receipt'];
    protected $casts = [
        'service_id' => 'integer', 'service_revision' => 'integer', 'admin_id' => 'integer',
        'writer_slot' => 'integer', 'release' => 'array', 'issued_at' => 'integer', 'expires_at' => 'integer',
        'revoked_at' => 'integer', 'revoked_by' => 'integer', 'released_at' => 'integer', 'released_by' => 'integer',
        'stop_receipt' => 'array', 'closure_receipt' => 'array',
    ];
}
