<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiDiagnosticReport extends Model
{
    protected $table = 'v2_ai_diagnostic_report';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];

    protected $casts = [
        'site_id' => 'integer',
        'score' => 'integer',
        'summary' => 'array',
        'metrics' => 'array',
        'findings' => 'array',
        'admin_id' => 'integer',
        'generated_at' => 'integer',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];
}
