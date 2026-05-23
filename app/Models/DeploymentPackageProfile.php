<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeploymentPackageProfile extends Model
{
    public const ENVIRONMENTS = ['local', 'staging', 'production'];

    public const DEPLOYMENT_TIERS = ['minimal', 'standard', 'enterprise'];

    protected $fillable = [
        'package_profile_id',
        'profile_name',
        'environment',
        'deployment_tier',
        'package_version',
        'checksum',
        'checksum_verified',
        'compatibility_verified',
        'is_active',
        'infrastructure_requirements',
        'package_manifest',
    ];

    protected $casts = [
        'checksum_verified'          => 'boolean',
        'compatibility_verified'     => 'boolean',
        'is_active'                  => 'boolean',
        'infrastructure_requirements'=> 'array',
        'package_manifest'           => 'array',
    ];
}
