<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EndpointNetworkCorrelation extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'snapshot_id', 'agent_id', 'pid', 'process_name',
        'remote_ip', 'remote_port', 'proto', 'correlation_confidence',
        'trace_id',
    ];

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(EndpointProcessSnapshot::class, 'snapshot_id');
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(EndpointAgent::class, 'agent_id');
    }
}
