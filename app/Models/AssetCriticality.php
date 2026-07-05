<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AssetCriticality extends Model
{
    public const TIERS = ['crown_jewel', 'high', 'medium', 'low'];

    protected $table = 'asset_criticality';

    protected $fillable = [
        'tenant_id',
        'asset_id',
        'criticality_tier',
        'justification',
        'assessed_by',
        'assessed_at',
    ];

    protected $casts = [
        'assessed_at' => 'datetime',
    ];

    public function asset()
    {
        return $this->belongsTo(AssetInventory::class, 'asset_id');
    }
}
