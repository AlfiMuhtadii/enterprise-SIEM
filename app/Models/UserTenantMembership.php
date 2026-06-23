<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserTenantMembership extends Model
{
    protected $table = 'user_tenant_memberships';

    protected $fillable = [
        'membership_id',
        'user_id',
        'tenant_id',
        'granted_by',
        'revoked_by',
        'granted_at',
        'revoked_at',
        'is_active',
    ];

    protected $casts = [
        'is_active'   => 'boolean',
        'granted_at'  => 'datetime',
        'revoked_at'  => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
