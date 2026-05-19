<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class InvestigationCollaborator extends Model
{
    public const UPDATED_AT = null; // append-only history

    public const ROLE_LEAD         = 'lead';
    public const ROLE_COLLABORATOR = 'collaborator';
    public const ROLE_REVIEWER     = 'reviewer';

    public const ROLES = [self::ROLE_LEAD, self::ROLE_COLLABORATOR, self::ROLE_REVIEWER];

    protected $fillable = [
        'collab_id', 'investigation_id', 'analyst_id', 'role',
        'added_by', 'active', 'note', 'trace_id',
    ];

    protected $casts = ['active' => 'boolean'];

    public static function generateCollabId(): string
    {
        return 'collab-' . Str::uuid();
    }

    public function analyst(): BelongsTo
    {
        return $this->belongsTo(User::class, 'analyst_id');
    }

    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by');
    }
}
