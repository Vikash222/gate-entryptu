<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Movement extends Model
{
    use HasFactory;

    public const TYPE_IN = 'IN';
    public const TYPE_OUT = 'OUT';

    protected $fillable = [
        'movement_uuid',
        'verification_code',
        'student_id',
        'gate_id',
        'security_user_id',
        'type',
        'vehicle_present',
        'vehicle_number',
        'destination',
        'destination_other',
        'purpose',
        'purpose_other',
        'client_request_id',
        'server_timestamp',
    ];

    protected function casts(): array
    {
        return [
            'vehicle_present' => 'boolean',
            'server_timestamp' => 'datetime',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function gate(): BelongsTo
    {
        return $this->belongsTo(Gate::class);
    }

    public function securityUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'security_user_id');
    }

    public function scopeToday(Builder $query): Builder
    {
        $todayStart = Carbon::now('Asia/Kolkata')->startOfDay();
        $todayEnd = Carbon::now('Asia/Kolkata')->endOfDay();
        return $query->whereBetween('server_timestamp', [$todayStart, $todayEnd]);
    }

    public function scopeLast15Days(Builder $query): Builder
    {
        $start = Carbon::now('Asia/Kolkata')->subDays(15)->startOfDay();
        return $query->where('server_timestamp', '>=', $start);
    }

    public function scopeLastYear(Builder $query): Builder
    {
        $start = Carbon::now('Asia/Kolkata')->subYear()->startOfDay();
        return $query->where('server_timestamp', '>=', $start);
    }

    public function isIn(): bool
    {
        return $this->type === self::TYPE_IN;
    }

    public function isOut(): bool
    {
        return $this->type === self::TYPE_OUT;
    }
}
