<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Honeytoken extends Model
{
    protected $fillable = [
        'honeytoken_id',
        'token_type',
        'token_value',
        'label',
        'tenant_id',
        'is_active',
        'created_by',
        'metadata',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'metadata' => 'array',
    ];

    public const TOKEN_TYPES = ['credential', 'file_path', 'url', 'dns_name'];

    public function hits()
    {
        return $this->hasMany(HoneytokenHit::class, 'honeytoken_id', 'honeytoken_id');
    }
}
