<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable rule version snapshot — append-only.
 * Each version records the rule state at a point in time.
 * Historical versions must never be mutated or deleted.
 */
class DetectionRuleVersion extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'version_id', 'rule_id', 'version', 'rule_hash', 'status', 'stage',
        'shadow_only', 'output_topic', 'owner', 'created_by', 'change_reason',
        'previous_version_id', 'rule_snapshot', 'created_at',
    ];

    protected $casts = [
        'shadow_only'   => 'boolean',
        'rule_snapshot' => 'array',
        'created_at'    => 'datetime',
    ];

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public static function generateVersionId(): string
    {
        return 'drv-' . substr(str_replace('-', '', (string) \Illuminate\Support\Str::uuid()), 0, 36);
    }

    /**
     * Deterministic rule hash from canonical fields.
     * Uses ksort + json_encode — same pattern as EndpointFleetPolicy.
     */
    public static function hashRule(array $rule): string
    {
        $canonical = [
            'rule_id'    => $rule['rule_id'] ?? '',
            'version'    => $rule['version'] ?? 'v1',
            'status'     => $rule['status'] ?? 'shadow',
            'domain'     => $rule['domain'] ?? '',
            'severity'   => $rule['severity'] ?? '',
            'confidence' => $rule['confidence'] ?? 0.0,
            'shadow_only'=> $rule['shadow_only'] ?? true,
            'output_topic'=> $rule['output_topic'] ?? '',
            'mitre_attack'=> $rule['mitre_attack'] ?? [],
        ];
        ksort($canonical);
        return hash('sha256', json_encode($canonical));
    }
}
