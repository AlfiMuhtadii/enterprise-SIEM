<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupportBundleExport extends Model
{
    public const BUNDLE_TYPES = [
        'diagnostics',
        'deployment',
        'queue',
        'storage',
        'environment',
        'audit',
    ];

    public const EXPORT_SCOPES = [
        'single_tenant',
        'environment',
        'infrastructure',
    ];

    protected $fillable = [
        'bundle_export_id',
        'bundle_type',
        'export_scope',
        'export_size_mb',
        'size_bounded',
        'destructive_action_taken',
        'replay_safe',
        'is_advisory',
        'bundle_evidence',
    ];

    protected $casts = [
        'export_size_mb'          => 'float',
        'size_bounded'            => 'boolean',
        'destructive_action_taken'=> 'boolean',
        'replay_safe'             => 'boolean',
        'is_advisory'             => 'boolean',
        'bundle_evidence'         => 'array',
    ];
}
