<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MachineOwnedInstance extends Model
{
    protected $table = 'v2_machine_owned_instance';
    protected $guarded = ['id'];
    protected $dateFormat = 'U';
    protected $hidden = ['challenge_id', 'challenge_deadline'];
    protected $casts = ['revision' => 'integer', 'policy_id' => 'integer', 'policy_revision' => 'integer', 'release_id' => 'integer',
        'resource_id' => 'integer', 'active_policy_id' => 'integer', 'verification' => 'array', 'verified_until' => 'integer',
        'challenge_deadline' => 'integer', 'created_at' => 'timestamp', 'updated_at' => 'timestamp'];
}
