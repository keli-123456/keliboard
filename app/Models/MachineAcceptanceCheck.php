<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MachineAcceptanceCheck extends Model
{
    protected $table = 'v2_machine_acceptance_check';
    protected $guarded = [];
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;
    protected $casts = ['instance_id' => 'integer', 'policy_revision' => 'integer', 'issued_at' => 'integer',
        'deadline_at' => 'integer', 'finished_at' => 'integer'];
}
