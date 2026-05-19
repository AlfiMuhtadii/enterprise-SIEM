<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CrossDomainCorrelation extends Model
{
    public const UPDATED_AT = null; // append-only

    public const TYPE_IDENTITY_ENDPOINT = 'identity_endpoint';
    public const TYPE_SAAS_ENDPOINT     = 'saas_endpoint';
    public const TYPE_CLOUD_ENDPOINT    = 'cloud_endpoint';
    public const TYPE_MULTI_HOST        = 'multi_host';
    public const TYPE_CROSS_DOMAIN      = 'cross_domain';

    public const TYPES = [
        self::TYPE_IDENTITY_ENDPOINT,
        self::TYPE_SAAS_ENDPOINT,
        self::TYPE_CLOUD_ENDPOINT,
        self::TYPE_MULTI_HOST,
        self::TYPE_CROSS_DOMAIN,
    ];

    protected $fillable = [
        'correlation_id', 'correlation_type', 'confidence_score',
        'attack_stages', 'primary_entity_type', 'primary_entity_key',
        'actor_key', 'involved_hosts', 'involved_users', 'involved_ips',
        'time_window_start', 'time_window_end', 'alert_ids', 'trace_ids',
        'correlation_metadata', 'created_by', 'trace_id',
    ];

    protected $casts = [
        'attack_stages'          => 'array',
        'involved_hosts'         => 'array',
        'involved_users'         => 'array',
        'involved_ips'           => 'array',
        'alert_ids'              => 'array',
        'trace_ids'              => 'array',
        'correlation_metadata'   => 'array',
        'time_window_start'      => 'datetime',
        'time_window_end'        => 'datetime',
        'created_at'             => 'datetime',
        'confidence_score'       => 'float',
    ];

    public static function generateCorrelationId(): string
    {
        $year = (int) date('Y');
        $max  = static::whereYear('created_at', $year)->max('id') ?? 0;
        return sprintf('CORR-%d-%05d', $year, $max + 1);
    }

    public function timelines(): HasMany
    {
        return $this->hasMany(AttackStageTimeline::class, 'correlation_id');
    }

    public function evidenceLinks(): HasMany
    {
        return $this->hasMany(CorrelationEvidenceLink::class, 'correlation_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
