<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * EasmAssetRiskScore — mutable (UPDATE allowed), DELETE permanently forbidden.
 * One record per asset, upserted on each completed passive scan.
 */
class EasmAssetRiskScore extends Model
{
    protected $table = 'easm_asset_risk_scores';

    protected $fillable = [
        'tenant_id',
        'asset_id',
        'risk_score',
        'risk_tier',
        'trend_direction',
        'previous_score',
        'total_scans',
        'last_computed_at',
    ];

    protected $casts = [
        'last_computed_at' => 'datetime',
        'risk_score'       => 'integer',
        'previous_score'   => 'integer',
        'total_scans'      => 'integer',
    ];

    public function delete(): ?bool
    {
        throw new LogicException('EasmAssetRiskScore records cannot be deleted.');
    }

    public function forceDelete(): ?bool
    {
        throw new LogicException('EasmAssetRiskScore records cannot be deleted.');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(WebsiteAsset::class, 'asset_id');
    }
}
