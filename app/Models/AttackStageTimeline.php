<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttackStageTimeline extends Model
{
    public const UPDATED_AT = null; // append-only

    public const STAGE_INITIAL_ACCESS      = 'initial_access';
    public const STAGE_CREDENTIAL_ACCESS   = 'credential_access';
    public const STAGE_EXECUTION           = 'execution';
    public const STAGE_PERSISTENCE         = 'persistence';
    public const STAGE_C2                  = 'command_and_control';
    public const STAGE_LATERAL_MOVEMENT    = 'lateral_movement';
    public const STAGE_EXFILTRATION        = 'exfiltration';
    public const STAGE_IMPACT              = 'impact';

    public const STAGES = [
        self::STAGE_INITIAL_ACCESS,
        self::STAGE_CREDENTIAL_ACCESS,
        self::STAGE_EXECUTION,
        self::STAGE_PERSISTENCE,
        self::STAGE_C2,
        self::STAGE_LATERAL_MOVEMENT,
        self::STAGE_EXFILTRATION,
        self::STAGE_IMPACT,
    ];

    public const STAGE_ORDER = [
        self::STAGE_INITIAL_ACCESS    => 1,
        self::STAGE_CREDENTIAL_ACCESS => 2,
        self::STAGE_EXECUTION         => 3,
        self::STAGE_PERSISTENCE       => 4,
        self::STAGE_C2                => 5,
        self::STAGE_LATERAL_MOVEMENT  => 6,
        self::STAGE_EXFILTRATION      => 7,
        self::STAGE_IMPACT            => 8,
    ];

    protected $fillable = [
        'timeline_id', 'correlation_id', 'stage', 'stage_order',
        'stage_confidence', 'evidence_summary', 'evidence_references',
        'first_seen_at', 'last_seen_at', 'trace_id',
    ];

    protected $casts = [
        'evidence_references' => 'array',
        'first_seen_at'       => 'datetime',
        'last_seen_at'        => 'datetime',
        'created_at'          => 'datetime',
        'stage_confidence'    => 'float',
    ];

    public static function generateTimelineId(): string
    {
        $year = (int) date('Y');
        $max  = static::whereYear('created_at', $year)->max('id') ?? 0;
        return sprintf('AST-%d-%05d', $year, $max + 1);
    }

    public function correlation(): BelongsTo
    {
        return $this->belongsTo(CrossDomainCorrelation::class, 'correlation_id');
    }
}
