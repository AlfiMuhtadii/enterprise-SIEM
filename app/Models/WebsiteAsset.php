<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WebsiteAsset extends Model
{
    protected $table = 'website_assets';

    protected $fillable = [
        'tenant_id',
        'name',
        'url',
        'hostname',
        'scheme',
        'status',
        'scan_policy',
        'last_scanned_at',
        'created_by',
    ];

    protected $casts = [
        'last_scanned_at' => 'datetime',
    ];

    public function scanRuns(): HasMany
    {
        return $this->hasMany(EasmScanRun::class, 'asset_id');
    }

    public function findings(): HasMany
    {
        return $this->hasMany(EasmFinding::class, 'asset_id');
    }
}
