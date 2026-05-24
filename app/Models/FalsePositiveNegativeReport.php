<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FalsePositiveNegativeReport extends Model
{
    public $timestamps = false;
    const CREATED_AT = 'created_at';

    protected $fillable = [
        'report_id', 'analyzed_rule_id', 'noisy_rule_count', 'repeated_benign_count',
        'suppressed_recurring_count', 'missed_chain_count', 'weak_evidence_count',
        'unstable_confidence_count', 'fp_prevalence_rate', 'fn_prevalence_rate',
        'advisory_only', 'no_auto_suppression', 'no_auto_closure', 'findings',
    ];

    protected $casts = [
        'advisory_only'      => 'boolean',
        'no_auto_suppression' => 'boolean',
        'no_auto_closure'    => 'boolean',
        'findings'           => 'array',
    ];
}
