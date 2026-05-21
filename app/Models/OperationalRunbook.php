<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OperationalRunbook extends Model
{
    protected $fillable = [
        'runbook_id', 'title', 'runbook_type', 'current_version', 'owner', 'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public const TYPES = [
        'deployment', 'rollback', 'incident_response', 'degraded_mode', 'replay_recovery',
    ];

    public function versions()
    {
        return $this->hasMany(OperationalRunbookVersion::class, 'runbook_id', 'runbook_id');
    }
}
