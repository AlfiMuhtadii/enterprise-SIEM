<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EndpointAgentHeartbeat extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'agent_id', 'tenant_id', 'signature', 'signature_valid', 'health_state',
        'ip_address', 'metrics', 'heartbeat_at',
    ];

    protected $casts = [
        'signature_valid' => 'boolean',
        'metrics'         => 'array',
        'heartbeat_at'    => 'datetime',
        'created_at'      => 'datetime',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(EndpointAgent::class, 'agent_id');
    }
}
