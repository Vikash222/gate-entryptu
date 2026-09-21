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

    public const SOURCE_QR = 'QR';
    public const SOURCE_SECURITY_MANUAL = 'SECURITY_MANUAL';

    protected $fillable = [
        'movement_uuid',
        'verification_code',
        'student_id',
        'gate_id',
        'security_user_id',
        'type',
        'movement_source',
        'is_late',
        'late_window_date',
        'day_scholar_after_hours',
        'vehicle_present',
        'vehicle_number',
        'destination',
        'destination_other',
        'purpose',
        'purpose_other',
        'client_request_id',
        'server_timestamp',
    ];

    protected $appends = [
        'DAY_SCHOLAR_AFTER_HOURS',
    ];

    protected function casts(): array
    {
        return [
            'is_late' => 'boolean',
            'late_window_date' => 'date:Y-m-d',
            'day_scholar_after_hours' => 'boolean',
            'vehicle_present' => 'boolean',
            'server_timestamp' => 'datetime',
        ];
    }

    /**
     * Centralized Domain Rule:
     * A movement is classified as LATE when its authoritative server timestamp in Asia/Kolkata
     * falls in the overnight window: 21:00:00 (9:00 PM) <= local_time < 04:00:00 (4:00 AM next day).
     */
    public static function isLateTimestamp(\Carbon\CarbonInterface|string $timestamp): bool
    {
        $carbon = is_string($timestamp)
            ? Carbon::parse($timestamp)->setTimezone('Asia/Kolkata')
            : $timestamp->copy()->setTimezone('Asia/Kolkata');

        $timeStr = $carbon->format('H:i:s');
        return ($timeStr >= '21:00:00' || $timeStr < '04:00:00');
    }

    /**
     * Calculate the Late Window Date (Asia/Kolkata):
     * If movement is late and occurs at or after 21:00:00, the window date is that calendar date.
     * If movement is late and occurs between 00:00:00 and 03:59:59 (overnight), it belongs
     * to the previous calendar date's late window.
     * If movement is not late, returns null.
     */
    public static function calculateLateWindowDate(\Carbon\CarbonInterface|string $timestamp): ?string
    {
        if (!self::isLateTimestamp($timestamp)) {
            return null;
        }

        $carbon = is_string($timestamp)
            ? Carbon::parse($timestamp)->setTimezone('Asia/Kolkata')
            : $timestamp->copy()->setTimezone('Asia/Kolkata');

        $timeStr = $carbon->format('H:i:s');
        if ($timeStr >= '21:00:00') {
            return $carbon->format('Y-m-d');
        }

        return $carbon->subDay()->format('Y-m-d');
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

    public function scopeLate(Builder $query): Builder
    {
        return $query->where('is_late', true);
    }

    public function scopeNormal(Builder $query): Builder
    {
        return $query->where('is_late', false);
    }

    public function scopeLateWindow(Builder $query, string $date): Builder
    {
        return $query->where('late_window_date', $date);
    }

    /**
     * Centralized Domain Rule:
     * Day Scholar After-Hours:
     * When a student has category DAY_SCHOLAR and the authoritative server timestamp in Asia/Kolkata
     * is at or after 5:00 PM (17:00:00) until morning opening (06:00:00).
     * This is a classification/warning rule, NEVER an access-blocking rule.
     */
    public static function isDayScholarAfterHours(?Student $student, \Carbon\CarbonInterface|string $timestamp): bool
    {
        if (!$student || !$student->isDayScholar()) {
            return false;
        }

        $carbon = is_string($timestamp)
            ? Carbon::parse($timestamp)->setTimezone('Asia/Kolkata')
            : $timestamp->copy()->setTimezone('Asia/Kolkata');

        $timeStr = $carbon->format('H:i:s');
        return ($timeStr >= '17:00:00' || $timeStr < '05:00:00');
    }

    public function scopeDayScholarAfterHours(Builder $query): Builder
    {
        return $query->where('day_scholar_after_hours', true);
    }

    public function getDayScholarAfterHoursAttribute($value = null): bool
    {
        if ($value !== null) {
            return (bool) $value;
        }
        return (bool) ($this->attributes['day_scholar_after_hours'] ?? false);
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
