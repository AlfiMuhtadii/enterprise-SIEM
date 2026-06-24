<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class EasmPostureSnapshot extends Model
{
    protected $table = 'easm_posture_snapshots';

    protected $fillable = [
        'tenant_id',
        'asset_id',
        'run_id',
        'posture_score',
        'risk_tier',
        'finding_count_total',
        'finding_count_high',
        'finding_count_medium',
        'finding_count_low',
        'finding_count_info',
        'new_findings',
        'resolved_findings',
        'regressed_findings',
        'unchanged_findings',
        'overall_status',
        'snapshot_at',
    ];

    protected $casts = [
        'snapshot_at'          => 'datetime',
        'posture_score'        => 'integer',
        'finding_count_total'  => 'integer',
        'finding_count_high'   => 'integer',
        'finding_count_medium' => 'integer',
        'finding_count_low'    => 'integer',
        'finding_count_info'   => 'integer',
        'new_findings'         => 'integer',
        'resolved_findings'    => 'integer',
        'regressed_findings'   => 'integer',
        'unchanged_findings'   => 'integer',
    ];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new LogicException('EasmPostureSnapshot is append-only and cannot be updated.');
        }
        return parent::save($options);
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(WebsiteAsset::class, 'asset_id');
    }
}
