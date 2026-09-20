<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Student extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'PENDING';
    public const STATUS_ACTIVE = 'ACTIVE';
    public const STATUS_REJECTED = 'REJECTED';
    public const STATUS_SUSPENDED = 'SUSPENDED';
    public const STATUS_INACTIVE = 'INACTIVE';

    public const STATE_INSIDE = 'INSIDE';
    public const STATE_OUTSIDE = 'OUTSIDE';

    protected $fillable = [
        'user_id',
        'student_id',
        'roll_number',
        'name',
        'year',
        'email',
        'phone_number',
        'program',
        'department',
        'semester',
        'batch',
        'profile_photo',
        'status',
        'current_status',
        'last_gate_id',
        'last_movement_at',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'semester' => 'integer',
            'last_movement_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function lastGate(): BelongsTo
    {
        return $this->belongsTo(Gate::class, 'last_gate_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(Movement::class);
    }

    public function gateSessions(): HasMany
    {
        return $this->hasMany(GateSession::class);
    }

    public function isInside(): bool
    {
        return $this->current_status === self::STATE_INSIDE;
    }

    public function isOutside(): bool
    {
        return $this->current_status === self::STATE_OUTSIDE;
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isRejected(): bool
    {
        return $this->status === self::STATUS_REJECTED;
    }

    public function isSuspended(): bool
    {
        return $this->status === self::STATUS_SUSPENDED;
    }
}
