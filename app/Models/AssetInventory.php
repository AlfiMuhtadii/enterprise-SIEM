<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AssetInventory extends Model
{
    public const ENVIRONMENTS = ['production', 'staging', 'development', 'test'];

    public const ASSET_TYPES = ['server', 'workstation', 'network_device', 'cloud_resource', 'other', 'unknown'];

    public const SOURCES = ['manual', 'csv_import'];

    protected $table = 'asset_inventory';

    protected $fillable = [
        'tenant_id',
        'external_id',
        'hostname',
        'ip_address',
        'owner',
        'environment',
        'asset_type',
        'source',
        'created_by',
    ];

    public function criticality()
    {
        return $this->hasOne(AssetCriticality::class, 'asset_id');
    }
}
