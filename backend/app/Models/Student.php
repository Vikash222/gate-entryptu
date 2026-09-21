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

    public const CATEGORY_HOSTELER = 'HOSTELER';
    public const CATEGORY_HOSTELLER = 'HOSTELLER';
    public const CATEGORY_DAY_SCHOLAR = 'DAY_SCHOLAR';

    public const TYPE_HOSTELLER = 'HOSTELLER';
    public const TYPE_DAY_SCHOLAR = 'DAY_SCHOLAR';

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
        'category',
        'student_type',
        'profile_photo',
        'status',
        'current_status',
        'last_gate_id',
        'last_movement_at',
    ];

    protected $appends = [
        'student_type',
        'profile_photo_url',
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

    public function setStudentTypeAttribute($value): void
    {
        $val = strtoupper(trim((string) $value));
        $this->attributes['student_type'] = $val;
        $this->attributes['category'] = ($val === self::TYPE_DAY_SCHOLAR) ? self::CATEGORY_DAY_SCHOLAR : self::CATEGORY_HOSTELER;
    }

    public function setCategoryAttribute($value): void
    {
        $val = strtoupper(trim((string) $value));
        $this->attributes['category'] = ($val === self::TYPE_DAY_SCHOLAR) ? self::CATEGORY_DAY_SCHOLAR : self::CATEGORY_HOSTELER;
        $this->attributes['student_type'] = ($val === self::TYPE_DAY_SCHOLAR) ? self::TYPE_DAY_SCHOLAR : self::TYPE_HOSTELLER;
    }

    public function getStudentTypeAttribute(): string
    {
        $val = strtoupper($this->attributes['student_type'] ?? $this->attributes['category'] ?? self::TYPE_HOSTELLER);
        return ($val === self::TYPE_DAY_SCHOLAR) ? self::TYPE_DAY_SCHOLAR : self::TYPE_HOSTELLER;
    }

    public function getCategoryAttribute(): string
    {
        $val = strtoupper($this->attributes['category'] ?? $this->attributes['student_type'] ?? self::CATEGORY_HOSTELER);
        return ($val === self::CATEGORY_DAY_SCHOLAR) ? self::CATEGORY_DAY_SCHOLAR : self::CATEGORY_HOSTELER;
    }

    public function isDayScholar(): bool
    {
        $val = strtoupper($this->attributes['student_type'] ?? $this->attributes['category'] ?? '');
        return $val === self::CATEGORY_DAY_SCHOLAR || $val === self::TYPE_DAY_SCHOLAR;
    }

    public function isHosteler(): bool
    {
        return !$this->isDayScholar();
    }

    public function hasProfilePhoto(): bool
    {
        return !empty($this->profile_photo);
    }

    public function getProfilePhotoUrlAttribute(): ?string
    {
        if (empty($this->profile_photo)) {
            return null;
        }

        return url('/api/v1/media/students/' . $this->id . '/photo');
    }
}
