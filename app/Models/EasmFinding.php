<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * EasmFinding — mutable (update allowed), but DELETE is permanently forbidden.
 * is_advisory is always true. Never auto-create SecurityIncident from findings.
 */
class EasmFinding extends Model
{
    protected $table = 'easm_findings';

    protected $fillable = [
        'tenant_id',
        'asset_id',
        'finding_key',
        'category',
        'severity',
        'confidence',
        'title',
        'description',
        'evidence',
        'recommendation',
        'scan_policy',
        'source',
        'status',
        'first_seen_at',
        'last_seen_at',
        'is_advisory',
    ];

    protected $casts = [
        'evidence'      => 'array',
        'is_advisory'   => 'boolean',
        'first_seen_at' => 'datetime',
        'last_seen_at'  => 'datetime',
    ];

    /**
     * DELETE is forbidden. Throw a LogicException if deletion is attempted.
     */
    public function delete(): ?bool
    {
        throw new LogicException('EasmFinding records are immutable — DELETE is permanently forbidden.');
    }

    /**
     * Force-delete also forbidden.
     */
    public function forceDelete(): ?bool
    {
        throw new LogicException('EasmFinding records are immutable — DELETE is permanently forbidden.');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(WebsiteAsset::class, 'asset_id');
    }
}
