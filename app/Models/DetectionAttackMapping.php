<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * MITRE ATT&CK technique mapping with confidence and evidence.
 * Extends the registry.v1.json mitre_attack array with richer metadata.
 * Operator-visible — no automatic promotion based solely on mapping.
 */
class DetectionAttackMapping extends Model
{
    protected $fillable = [
        'mapping_id', 'rule_id', 'tactic', 'technique_id', 'technique_name',
        'sub_technique_id', 'confidence', 'mapping_source', 'evidence_reference',
        'created_by', 'is_active',
    ];

    protected $casts = [
        'confidence' => 'float',
        'is_active'  => 'boolean',
    ];

    // Mapping sources
    public const SOURCE_REGISTRY      = 'registry';
    public const SOURCE_ANALYST       = 'analyst';
    public const SOURCE_THREAT_INTEL  = 'threat_intel';
    public const SOURCE_TEST_VALIDATION = 'test_validation';

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public static function generateMappingId(): string
    {
        return 'dam-' . substr(str_replace('-', '', (string) \Illuminate\Support\Str::uuid()), 0, 36);
    }
}
