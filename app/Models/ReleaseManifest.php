<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReleaseManifest extends Model
{
    protected $fillable = [
        'release_id', 'release_version', 'component_versions', 'migration_versions',
        'schema_versions', 'replay_validator_version', 'release_notes',
        'rollback_reference', 'manifest_hash', 'status', 'created_by',
    ];

    protected $casts = [
        'component_versions' => 'array',
        'migration_versions' => 'array',
        'schema_versions'    => 'array',
    ];

    public const STATUS_DRAFT     = 'draft';
    public const STATUS_APPROVED  = 'approved';
    public const STATUS_DEPLOYED  = 'deployed';
    public const STATUS_ROLLED_BACK = 'rolled_back';

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new \LogicException('ReleaseManifest is append-only and cannot be updated.');
        }
        if (empty($this->created_at)) {
            $this->created_at = now();
            $this->updated_at = now();
        }
        return parent::save($options);
    }
}
