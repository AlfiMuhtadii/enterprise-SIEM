<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExportAuditLog extends Model
{
    protected $fillable = [
        'export_id', 'export_type', 'export_format',
        'exported_by', 'exported_at', 'export_reason',
        'source_id', 'source_type', 'export_size_bytes', 'metadata', 'tenant_id',
    ];

    protected $casts = [
        'exported_at'       => 'datetime',
        'export_size_bytes' => 'integer',
        'metadata'          => 'array',
    ];

    public const EXPORT_TYPES = [
        'investigation', 'response_plan', 'entity_risk', 'trace', 'incident_bundle',
    ];

    public const FORMATS = ['json', 'markdown', 'html'];

    public function exportedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'exported_by');
    }
}
