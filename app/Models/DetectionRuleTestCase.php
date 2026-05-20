<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DetectionRuleTestCase extends Model
{
    protected $fillable = [
        'test_case_id', 'rule_id', 'name', 'description', 'expected_outcome',
        'event_fixtures', 'fixture_path', 'expected_fields', 'expected_severity',
        'expect_trace_id', 'is_active', 'created_by',
    ];

    protected $casts = [
        'event_fixtures'  => 'array',
        'expected_fields' => 'array',
        'expect_trace_id' => 'boolean',
        'is_active'       => 'boolean',
    ];

    public const OUTCOME_TRUE_POSITIVE  = 'true_positive';
    public const OUTCOME_FALSE_POSITIVE = 'false_positive';
    public const OUTCOME_TRUE_NEGATIVE  = 'true_negative';

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public static function generateTestCaseId(): string
    {
        return 'dtc-' . substr(str_replace('-', '', (string) \Illuminate\Support\Str::uuid()), 0, 36);
    }
}
