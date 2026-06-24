<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class EasmScanRun extends Model
{
    protected $table = 'easm_scan_runs';

    protected $fillable = [
        'run_id',
        'tenant_id',
        'asset_id',
        'scan_policy',
        'target_hostname',
        'target_url',
        'status',
        'started_at',
        'finished_at',
        'finding_count',
        'checks_run',
        'error_summary',
        'is_dry_run',
        'actor',
    ];

    protected $casts = [
        'started_at'   => 'datetime',
        'finished_at'  => 'datetime',
        'checks_run'   => 'array',
        'is_dry_run'   => 'boolean',
        'finding_count' => 'integer',
    ];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new LogicException('EasmScanRun is append-only and cannot be updated.');
        }
        return parent::save($options);
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(WebsiteAsset::class, 'asset_id');
    }
}
