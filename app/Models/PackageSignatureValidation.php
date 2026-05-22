<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PackageSignatureValidation extends Model
{
    protected $fillable = [
        'validation_id', 'agent_id', 'package_name', 'package_version',
        'expected_hash', 'observed_hash', 'signer', 'signature_valid',
        'hash_valid', 'verdict', 'validated_by',
    ];

    protected $casts = [
        'signature_valid' => 'boolean',
        'hash_valid'      => 'boolean',
    ];

    public const VERDICT_PASS    = 'pass';
    public const VERDICT_FAIL    = 'fail';
    public const VERDICT_UNKNOWN = 'unknown';

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new \LogicException('PackageSignatureValidation is append-only and cannot be updated.');
        }
        if (empty($this->created_at)) {
            $this->created_at = now();
            $this->updated_at = now();
        }
        return parent::save($options);
    }
}
