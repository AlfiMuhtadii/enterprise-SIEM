<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class EasmFindingChange extends Model
{
    protected $table = 'easm_finding_changes';

    protected $fillable = [
        'tenant_id',
        'asset_id',
        'run_id',
        'finding_key',
        'change_type',
        'severity_before',
        'severity_after',
        'changed_at',
    ];

    protected $casts = [
        'changed_at' => 'datetime',
    ];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new LogicException('EasmFindingChange is append-only and cannot be updated.');
        }
        return parent::save($options);
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(WebsiteAsset::class, 'asset_id');
    }
}
