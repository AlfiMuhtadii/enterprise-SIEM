<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class DeploymentPackageManifest extends Model
{
    protected $table = 'deployment_package_manifests';

    protected $fillable = [
        'manifest_id', 'package_id', 'version', 'package_hash_sha256',
        'image_digest', 'is_signed', 'signature_valid',
        'dependency_integrity_verified', 'is_advisory', 'manifest_payload',
    ];

    protected $casts = [
        'is_signed'                      => 'boolean',
        'signature_valid'                => 'boolean',
        'dependency_integrity_verified'  => 'boolean',
        'is_advisory'                    => 'boolean',
        'manifest_payload'               => 'array',
    ];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new LogicException('DeploymentPackageManifest is append-only and cannot be updated.');
        }
        return parent::save($options);
    }
}
