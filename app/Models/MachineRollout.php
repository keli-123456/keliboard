<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MachineRollout extends Model
{
    protected $table = 'v2_machine_rollout';
    protected $guarded = [];
    public $incrementing = false;
    protected $keyType = 'string';
    protected $casts = ['release' => 'array', 'observe_seconds' => 'integer', 'last_tick_at' => 'integer', 'created_at' => 'timestamp', 'updated_at' => 'timestamp'];

    public function targets()
    {
        return $this->hasMany(MachineRolloutTarget::class, 'rollout_id')->orderBy('position');
    }
}
