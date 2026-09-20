<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    use HasFactory;

    public const STATUS_SUCCESS = 'SUCCESS';
    public const STATUS_FAILED = 'FAILED';
    public const STATUS_WARNING = 'WARNING';

    protected $fillable = [
        'user_id',
        'action',
        'module',
        'entity_type',
        'entity_id',
        'ip_address',
        'user_agent',
        'status',
        'metadata',
        'server_timestamp',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'server_timestamp' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
