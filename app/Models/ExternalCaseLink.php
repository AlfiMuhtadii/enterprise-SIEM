<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExternalCaseLink extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'link_id', 'investigation_id', 'integration_id', 'external_ticket_id',
        'external_ticket_url', 'external_status', 'link_direction',
        'sync_advisory_only', 'auto_closed', 'external_metadata', 'trace_id', 'created_by',
    ];

    protected $casts = [
        'external_metadata'  => 'array',
        'sync_advisory_only' => 'boolean',
        'auto_closed'        => 'boolean',
    ];

    public const DIRECTIONS = ['soc_to_external', 'external_to_soc'];

    public function integration(): BelongsTo
    {
        return $this->belongsTo(ExternalIntegration::class, 'integration_id');
    }
}
