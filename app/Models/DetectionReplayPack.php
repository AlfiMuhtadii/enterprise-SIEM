<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DetectionReplayPack extends Model
{
    protected $fillable = [
        'pack_id', 'rule_id', 'name', 'description',
        'expected_match_count', 'expected_non_match_count',
        'pack_version', 'is_active', 'created_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function results(): HasMany
    {
        return $this->hasMany(DetectionReplayResult::class, 'pack_id', 'pack_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public static function generatePackId(): string
    {
        return 'drp-' . substr(str_replace('-', '', (string) \Illuminate\Support\Str::uuid()), 0, 36);
    }
}
