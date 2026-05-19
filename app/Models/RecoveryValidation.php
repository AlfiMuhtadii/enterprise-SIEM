<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class RecoveryValidation extends Model
{
    public const UPDATED_AT = null; // append-only

    public const TYPE_INTEGRITY  = 'integrity';
    public const TYPE_REPLAY     = 'replay';
    public const TYPE_SCHEMA     = 'schema';
    public const TYPE_CHECKSUM   = 'checksum';
    public const TYPE_ROW_COUNT  = 'row_count';

    public const RESULT_PASS    = 'pass';
    public const RESULT_FAIL    = 'fail';
    public const RESULT_WARNING = 'warning';
    public const RESULT_SKIPPED = 'skipped';

    protected $fillable = [
        'validation_id', 'backup_id', 'validation_type', 'result',
        'details', 'validated_by', 'trace_id',
    ];

    protected $casts = ['details' => 'array'];

    public static function generateValidationId(): string
    {
        return 'rcval-' . Str::uuid();
    }
}
