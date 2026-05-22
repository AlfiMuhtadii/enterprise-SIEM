<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class FalsePositiveTuningReport extends Model
{
    public const TUNING_ACTIONS = ['suppress', 'tune_threshold', 'add_allowlist', 'review_deferred'];
    public const MAX_SUPPRESSION_DAYS = 30;

    protected $fillable = [
        'report_id', 'rule_id', 'tenant_id', 'analyst_id', 'tuning_action',
        'suppression_scope', 'suppression_duration_days', 'fp_rate_before',
        'fp_rate_after_estimate', 'replay_validated', 'expiry_tracked', 'is_advisory', 'evidence',
    ];

    protected $casts = [
        'fp_rate_before'        => 'float',
        'fp_rate_after_estimate'=> 'float',
        'replay_validated'      => 'boolean',
        'expiry_tracked'        => 'boolean',
        'is_advisory'           => 'boolean',
        'evidence'              => 'array',
    ];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new LogicException('FalsePositiveTuningReport is append-only and cannot be updated.');
        }
        return parent::save($options);
    }
}
