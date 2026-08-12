<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiDiagnosticIncidentLog extends Model
{
    protected $table = 'v2_ai_diagnostic_incident_log';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];

    protected $casts = [
        'incident_id' => 'integer',
        'admin_id' => 'integer',
        'metadata' => 'array',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }
}
