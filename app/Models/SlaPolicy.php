<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class SlaPolicy extends Model
{
    public const TYPE_TRIAGE        = 'triage';
    public const TYPE_ESCALATION    = 'escalation';
    public const TYPE_RESPONSE      = 'response';
    public const TYPE_INVESTIGATION = 'investigation';

    public const TYPES = [self::TYPE_TRIAGE, self::TYPE_ESCALATION, self::TYPE_RESPONSE, self::TYPE_INVESTIGATION];

    // Default SLA thresholds in seconds
    public const DEFAULTS = [
        self::TYPE_TRIAGE => [
            'critical' => 3600,       // 1h
            'high'     => 14400,      // 4h
            'medium'   => 86400,      // 24h
            'low'      => 259200,     // 72h
        ],
        self::TYPE_ESCALATION => [
            'critical' => 1800,       // 30min
            'high'     => 7200,       // 2h
            'medium'   => 28800,      // 8h
            'low'      => 86400,      // 24h
        ],
        self::TYPE_RESPONSE => [
            'critical' => 7200,       // 2h
            'high'     => 28800,      // 8h
            'medium'   => 172800,     // 48h
            'low'      => 604800,     // 7d
        ],
        self::TYPE_INVESTIGATION => [
            'critical' => 86400,      // 24h
            'high'     => 259200,     // 72h
            'medium'   => 604800,     // 7d
            'low'      => 2592000,    // 30d
        ],
    ];

    protected $fillable = [
        'policy_id', 'policy_type', 'severity', 'max_seconds', 'active', 'description',
    ];

    protected $casts = ['active' => 'boolean'];

    public static function generatePolicyId(): string
    {
        return 'sla-' . Str::uuid();
    }
}
