<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MachineFailoverTriggerPreview extends Model
{
    protected $table = 'v2_machine_failover_trigger_preview';
    protected $guarded = ['id'];
    public $timestamps = false;
    protected $hidden = ['dedup_key', 'episode_key', 'context_hash', 'source_hash'];
    protected $casts = ['reasons' => 'array', 'service_id' => 'integer', 'member_id' => 'integer', 'policy_id' => 'integer',
        'machine_id' => 'integer', 'service_revision' => 'integer', 'policy_revision' => 'integer', 'planned_cents' => 'integer',
        'source_checked_at' => 'integer', 'checked_at' => 'integer', 'valid_until' => 'integer', 'revision' => 'integer',
        'closed_at' => 'integer', 'created_at' => 'integer', 'updated_at' => 'integer'];
}
