<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MachineOperationNotice extends Model
{
    protected $table = 'v2_machine_operation_notice';
    protected $guarded = ['id'];
    protected $dateFormat = 'U';
    protected $hidden = ['context_hash', 'event_key'];
    protected $casts = ['snapshot' => 'array', 'source_id' => 'integer', 'resource_id' => 'integer',
        'policy_revision' => 'integer', 'claimed_at' => 'integer', 'next_dispatch_at' => 'integer', 'expires_at' => 'integer',
        'created_at' => 'timestamp', 'updated_at' => 'timestamp'];
}
