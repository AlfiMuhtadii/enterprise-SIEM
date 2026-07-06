<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Tenant extends Model
{
    protected $fillable = ['tenant_id', 'name', 'status'];

    public const STATUSES = ['active', 'suspended'];
}
