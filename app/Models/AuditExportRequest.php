<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AuditExportRequest extends Model
{
    protected $fillable = [
        'export_id', 'scope', 'scope_id', 'export_format', 'status',
        'integrity_checksum', 'pii_masked', 'export_reason', 'tenant_scope',
        'requested_by', 'approved_by', 'approved_at', 'expires_at', 'packaged_at',
    ];

    protected $casts = [
        'pii_masked'  => 'boolean',
        'approved_at' => 'datetime',
        'expires_at'  => 'datetime',
        'packaged_at' => 'datetime',
    ];

    public const STATUSES = ['draft', 'pending_approval', 'approved', 'packaged', 'expired', 'rejected'];
    public const SCOPES   = ['investigation', 'trace', 'incident', 'hunt_results', 'full_audit'];
    public const FORMATS  = ['json', 'markdown', 'html'];

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function accessLogs(): HasMany
    {
        return $this->hasMany(AuditExportAccessLog::class, 'export_id', 'export_id');
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
