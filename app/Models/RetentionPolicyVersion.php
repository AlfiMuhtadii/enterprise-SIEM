<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RetentionPolicyVersion extends Model
{
    protected $fillable = [
        'version_id', 'policy_id', 'version', 'version_hash', 'policy_snapshot',
        'review_status', 'review_notes', 'reviewed_by', 'reviewed_at',
        'change_reason', 'created_by',
    ];

    protected $casts = [
        'policy_snapshot' => 'array',
        'reviewed_at'     => 'datetime',
    ];

    public const REVIEW_STATUSES = ['pending', 'reviewed', 'approved', 'rejected'];

    public static function hashPolicy(array $snapshot): string
    {
        $fields = [
            'domain'         => $snapshot['domain'] ?? null,
            'target_table'   => $snapshot['target_table'] ?? null,
            'retention_days' => $snapshot['retention_days'] ?? null,
            'max_rows'       => $snapshot['max_rows'] ?? null,
            'status'         => $snapshot['status'] ?? null,
        ];
        ksort($fields);
        return hash('sha256', json_encode($fields));
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
