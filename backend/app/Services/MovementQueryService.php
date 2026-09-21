<?php

namespace App\Services;

use App\Models\Gate;
use App\Models\Movement;
use App\Models\Student;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MovementQueryService
{
    /**
     * Build the standardized Movement query applying all validated filters.
     * Enforces the authoritative 1-year historical access boundary.
     */
    public function buildQuery(Request|array $params): Builder
    {
        $filters = $params instanceof Request ? $params->all() : $params;

        $oneYearLimit = Carbon::now('Asia/Kolkata')->subYear()->startOfDay();

        $query = Movement::query()
            ->with(['student', 'gate', 'securityUser'])
            ->where('server_timestamp', '>=', $oneYearLimit);

        // 1. Date presets
        $preset = strtolower(trim($filters['date_preset'] ?? ''));
        if ($preset) {
            $now = Carbon::now('Asia/Kolkata');
            if ($preset === 'today') {
                $query->whereBetween('server_timestamp', [$now->copy()->startOfDay(), $now->copy()->endOfDay()]);
            } elseif ($preset === 'yesterday') {
                $yesterday = $now->copy()->subDay();
                $query->whereBetween('server_timestamp', [$yesterday->copy()->startOfDay(), $yesterday->copy()->endOfDay()]);
            } elseif ($preset === 'this_week') {
                $query->whereBetween('server_timestamp', [$now->copy()->startOfWeek(), $now->copy()->endOfWeek()]);
            } elseif ($preset === 'this_month') {
                $query->whereBetween('server_timestamp', [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()]);
            }
        }

        // 2. Specific calendar date (Asia/Kolkata)
        if (!empty($filters['date'])) {
            $date = Carbon::parse($filters['date'], 'Asia/Kolkata');
            $query->whereBetween('server_timestamp', [$date->copy()->startOfDay(), $date->copy()->endOfDay()]);
        }

        // 3. Date range
        if (!empty($filters['start_date'])) {
            $start = Carbon::parse($filters['start_date'], 'Asia/Kolkata')->startOfDay();
            $query->where('server_timestamp', '>=', $start);
        }

        if (!empty($filters['end_date'])) {
            $end = Carbon::parse($filters['end_date'], 'Asia/Kolkata')->endOfDay();
            $query->where('server_timestamp', '<=', $end);
        }

        // 4. Late Filter (is_late)
        $late = $filters['late'] ?? ($filters['is_late'] ?? null);
        if ($late !== null && $late !== '' && $late !== 'ALL') {
            $isLate = filter_var($late, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($isLate !== null) {
                $query->where('is_late', $isLate);
            } elseif (in_array(strtoupper((string) $late), ['LATE', 'YES', '1'])) {
                $query->where('is_late', true);
            } elseif (in_array(strtoupper((string) $late), ['NORMAL', 'NO', '0'])) {
                $query->where('is_late', false);
            }
        }

        // 5. Late Window Date (Overnight grouping date)
        if (!empty($filters['late_window_date'])) {
            $windowDate = Carbon::parse($filters['late_window_date'])->format('Y-m-d');
            $query->where('late_window_date', $windowDate);
        }

        // 6. Movement Type (IN / OUT)
        $type = strtoupper(trim($filters['type'] ?? ($filters['movement_type'] ?? '')));
        if (in_array($type, [Movement::TYPE_IN, Movement::TYPE_OUT], true)) {
            $query->where('type', $type);
        }

        // 7. Movement Source (QR / SECURITY_MANUAL)
        $source = strtoupper(trim($filters['movement_source'] ?? ($filters['source'] ?? '')));
        if (in_array($source, [Movement::SOURCE_QR, Movement::SOURCE_SECURITY_MANUAL], true)) {
            $query->where('movement_source', $source);
        }

        // 8. Gate Filter
        if (!empty($filters['gate_id'])) {
            $query->where('gate_id', (int) $filters['gate_id']);
        }

        // 9. Student Filter
        if (!empty($filters['student_id'])) {
            $query->where('student_id', (int) $filters['student_id']);
        }

        if (!empty($filters['roll_number'])) {
            $roll = trim($filters['roll_number']);
            $query->whereHas('student', fn ($sq) => $sq->where('roll_number', $roll));
        }

        // 10. General Search (Roll, Name, Student ID, Verification Code, Vehicle Number)
        if (!empty($filters['search'])) {
            $s = trim($filters['search']);
            $query->where(function ($sub) use ($s) {
                $sub->where('verification_code', 'LIKE', "%{$s}%")
                    ->orWhere('vehicle_number', 'LIKE', "%{$s}%")
                    ->orWhereHas('student', function ($sq) use ($s) {
                        $sq->where('roll_number', 'LIKE', "%{$s}%")
                            ->orWhere('name', 'LIKE', "%{$s}%")
                            ->orWhere('student_id', 'LIKE', "%{$s}%");
                    });
            });
        }

        // 11. Destination & Purpose
        if (!empty($filters['destination'])) {
            $query->where('destination', $filters['destination']);
        }

        if (!empty($filters['purpose'])) {
            $query->where('purpose', $filters['purpose']);
        }

        // 12. Vehicle Present
        if (isset($filters['vehicle_present']) && $filters['vehicle_present'] !== '') {
            $vp = filter_var($filters['vehicle_present'], FILTER_VALIDATE_BOOLEAN);
            $query->where('vehicle_present', $vp);
        }

        // 13. Day Scholar After-Hours Filter
        $afterHours = $filters['day_scholar_after_hours'] ?? ($filters['after_hours'] ?? null);
        if ($afterHours !== null && $afterHours !== '' && $afterHours !== 'ALL') {
            $isAfterHours = filter_var($afterHours, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($isAfterHours !== null) {
                $query->where('day_scholar_after_hours', $isAfterHours);
            } elseif (in_array(strtoupper((string) $afterHours), ['AFTER_HOURS', 'YES', '1', 'TRUE'])) {
                $query->where('day_scholar_after_hours', true);
            } elseif (in_array(strtoupper((string) $afterHours), ['NORMAL', 'NO', '0', 'FALSE'])) {
                $query->where('day_scholar_after_hours', false);
            }
        }

        // 14. Student Type / Category Filter (HOSTELLER / DAY_SCHOLAR)
        $rawType = $filters['student_type'] ?? ($filters['student_category'] ?? ($filters['category'] ?? null));
        if (!empty($rawType) && strtoupper(trim((string) $rawType)) !== 'ALL') {
            $cat = strtoupper(trim((string) $rawType));
            if ($cat === 'HOSTELER' || $cat === 'HOSTELLER') {
                $query->whereHas('student', fn ($sq) => $sq->where(function ($q) {
                    $q->where('category', 'HOSTELER')
                      ->orWhere('category', 'HOSTELLER')
                      ->orWhere('student_type', 'HOSTELLER');
                }));
            } elseif ($cat === 'DAY_SCHOLAR') {
                $query->whereHas('student', fn ($sq) => $sq->where(function ($q) {
                    $q->where('category', 'DAY_SCHOLAR')
                      ->orWhere('student_type', 'DAY_SCHOLAR');
                }));
            }
        }

        return $query;
    }

    /**
     * Compute comprehensive, factual analytics on the filtered dataset.
     */
    public function getAnalytics(Request|array $params): array
    {
        // Base query without eager loading for aggregate speed
        $baseQuery = $this->buildQuery($params);

        // 1. Overall KPI metrics
        $stats = (clone $baseQuery)->selectRaw("
            COUNT(*) as total_movements,
            COUNT(CASE WHEN type = 'IN' THEN 1 END) as total_in,
            COUNT(CASE WHEN type = 'OUT' THEN 1 END) as total_out,
            COUNT(CASE WHEN is_late = 1 THEN 1 END) as total_late,
            COUNT(CASE WHEN is_late = 0 THEN 1 END) as total_normal,
            COUNT(CASE WHEN is_late = 1 AND type = 'IN' THEN 1 END) as late_in,
            COUNT(CASE WHEN is_late = 1 AND type = 'OUT' THEN 1 END) as late_out,
            COUNT(CASE WHEN day_scholar_after_hours = 1 THEN 1 END) as total_after_hours,
            COUNT(DISTINCT student_id) as unique_students,
            COUNT(DISTINCT security_user_id) as unique_guards
        ")->first();

        $totalMovements = (int) ($stats->total_movements ?? 0);
        $totalIn = (int) ($stats->total_in ?? 0);
        $totalOut = (int) ($stats->total_out ?? 0);
        $totalLate = (int) ($stats->total_late ?? 0);
        $totalNormal = (int) ($stats->total_normal ?? 0);
        $lateIn = (int) ($stats->late_in ?? 0);
        $lateOut = (int) ($stats->late_out ?? 0);
        $totalAfterHours = (int) ($stats->total_after_hours ?? 0);
        $uniqueStudents = (int) ($stats->unique_students ?? 0);
        $uniqueGuards = (int) ($stats->unique_guards ?? 0);

        // 2. Gate-wise analytics
        $gates = Gate::all();
        $gateAggregates = (clone $baseQuery)
            ->selectRaw("
                gate_id,
                COUNT(*) as total,
                COUNT(CASE WHEN type = 'IN' THEN 1 END) as in_count,
                COUNT(CASE WHEN type = 'OUT' THEN 1 END) as out_count,
                COUNT(CASE WHEN is_late = 1 THEN 1 END) as late_count,
                COUNT(CASE WHEN is_late = 0 THEN 1 END) as normal_count,
                COUNT(CASE WHEN is_late = 1 AND type = 'IN' THEN 1 END) as late_in,
                COUNT(CASE WHEN is_late = 1 AND type = 'OUT' THEN 1 END) as late_out
            ")
            ->groupBy('gate_id')
            ->get()
            ->keyBy('gate_id');

        $gateStats = [];
        foreach ($gates as $g) {
            $agg = $gateAggregates->get($g->id);
            $gateStats[] = [
                'gate_id' => $g->id,
                'gate_name' => $g->name,
                'gate_code' => $g->code,
                'total' => (int) ($agg->total ?? 0),
                'in' => (int) ($agg->in_count ?? 0),
                'out' => (int) ($agg->out_count ?? 0),
                'late' => (int) ($agg->late_count ?? 0),
                'normal' => (int) ($agg->normal_count ?? 0),
                'late_in' => (int) ($agg->late_in ?? 0),
                'late_out' => (int) ($agg->late_out ?? 0),
            ];
        }

        // 3. Movement Source analytics
        $sourceAggregates = (clone $baseQuery)
            ->selectRaw("
                movement_source,
                COUNT(*) as total,
                COUNT(CASE WHEN type = 'IN' THEN 1 END) as in_count,
                COUNT(CASE WHEN type = 'OUT' THEN 1 END) as out_count,
                COUNT(CASE WHEN is_late = 1 THEN 1 END) as late_count,
                COUNT(CASE WHEN is_late = 0 THEN 1 END) as normal_count,
                COUNT(CASE WHEN is_late = 1 AND type = 'IN' THEN 1 END) as late_in,
                COUNT(CASE WHEN is_late = 1 AND type = 'OUT' THEN 1 END) as late_out
            ")
            ->groupBy('movement_source')
            ->get()
            ->keyBy('movement_source');

        $sourceStats = [
            'QR' => [
                'source' => 'QR',
                'movement_source' => 'QR',
                'label' => 'Student QR Self-Service',
                'total' => (int) ($sourceAggregates->get(Movement::SOURCE_QR)?->total ?? 0),
                'in' => (int) ($sourceAggregates->get(Movement::SOURCE_QR)?->in_count ?? 0),
                'out' => (int) ($sourceAggregates->get(Movement::SOURCE_QR)?->out_count ?? 0),
                'late' => (int) ($sourceAggregates->get(Movement::SOURCE_QR)?->late_count ?? 0),
                'normal' => (int) ($sourceAggregates->get(Movement::SOURCE_QR)?->normal_count ?? 0),
                'late_in' => (int) ($sourceAggregates->get(Movement::SOURCE_QR)?->late_in ?? 0),
                'late_out' => (int) ($sourceAggregates->get(Movement::SOURCE_QR)?->late_out ?? 0),
            ],
            'SECURITY_MANUAL' => [
                'source' => 'SECURITY_MANUAL',
                'movement_source' => 'SECURITY_MANUAL',
                'label' => 'Security-Assisted Manual Entry',
                'total' => (int) ($sourceAggregates->get(Movement::SOURCE_SECURITY_MANUAL)?->total ?? 0),
                'in' => (int) ($sourceAggregates->get(Movement::SOURCE_SECURITY_MANUAL)?->in_count ?? 0),
                'out' => (int) ($sourceAggregates->get(Movement::SOURCE_SECURITY_MANUAL)?->out_count ?? 0),
                'late' => (int) ($sourceAggregates->get(Movement::SOURCE_SECURITY_MANUAL)?->late_count ?? 0),
                'normal' => (int) ($sourceAggregates->get(Movement::SOURCE_SECURITY_MANUAL)?->normal_count ?? 0),
                'late_in' => (int) ($sourceAggregates->get(Movement::SOURCE_SECURITY_MANUAL)?->late_in ?? 0),
                'late_out' => (int) ($sourceAggregates->get(Movement::SOURCE_SECURITY_MANUAL)?->late_out ?? 0),
            ],
        ];

        // 4. Daily movement & late trends (Calendar Day)
        $dailyTrends = (clone $baseQuery)
            ->selectRaw("
                DATE(server_timestamp) as movement_date,
                COUNT(*) as total,
                COUNT(CASE WHEN type = 'IN' THEN 1 END) as in_count,
                COUNT(CASE WHEN type = 'OUT' THEN 1 END) as out_count,
                COUNT(CASE WHEN is_late = 1 THEN 1 END) as late_count,
                COUNT(CASE WHEN is_late = 0 THEN 1 END) as normal_count
            ")
            ->groupBy(DB::raw('DATE(server_timestamp)'))
            ->orderBy(DB::raw('DATE(server_timestamp)'), 'asc')
            ->limit(30)
            ->get()
            ->map(fn ($r) => [
                'date' => (string) $r->movement_date,
                'total' => (int) $r->total,
                'in' => (int) $r->in_count,
                'out' => (int) $r->out_count,
                'late' => (int) $r->late_count,
                'normal' => (int) $r->normal_count,
            ]);

        // 5. Overnight Late-Window trends (by late_window_date)
        $lateWindowTrends = (clone $baseQuery)
            ->whereNotNull('late_window_date')
            ->selectRaw("
                late_window_date,
                COUNT(*) as total,
                COUNT(CASE WHEN type = 'IN' THEN 1 END) as in_count,
                COUNT(CASE WHEN type = 'OUT' THEN 1 END) as out_count
            ")
            ->groupBy('late_window_date')
            ->orderBy('late_window_date', 'asc')
            ->limit(30)
            ->get()
            ->map(fn ($r) => [
                'late_window_date' => (string) $r->late_window_date,
                'late_total' => (int) $r->total,
                'late_in' => (int) $r->in_count,
                'late_out' => (int) $r->out_count,
            ]);

        return [
            'summary' => [
                'total_movements' => $totalMovements,
                'total_in' => $totalIn,
                'in_count' => $totalIn,
                'total_out' => $totalOut,
                'out_count' => $totalOut,
                'total_late' => $totalLate,
                'late_count' => $totalLate,
                'total_normal' => $totalNormal,
                'normal_count' => $totalNormal,
                'late_in' => $lateIn,
                'late_in_count' => $lateIn,
                'late_out' => $lateOut,
                'late_out_count' => $lateOut,
                'total_after_hours' => $totalAfterHours,
                'after_hours_count' => $totalAfterHours,
                'unique_students' => $uniqueStudents,
                'unique_guards' => $uniqueGuards,
            ],
            'gate_stats' => $gateStats,
            'source_stats' => array_values($sourceStats),
            'daily_trends' => $dailyTrends,
            'late_window_trends' => $lateWindowTrends,
            'server_time' => now('Asia/Kolkata')->toIso8601String(),
        ];
    }

    /**
     * Get a student-specific report matching filters.
     */
    public function getStudentReport(int $studentId, Request|array $params): array
    {
        $student = Student::with('lastGate')->findOrFail($studentId);

        $studentParams = $params instanceof Request ? $params->all() : $params;
        $studentParams['student_id'] = $studentId;

        $query = $this->buildQuery($studentParams)->orderByDesc('server_timestamp');

        $stats = (clone $query)->selectRaw("
            COUNT(*) as total_movements,
            COUNT(CASE WHEN type = 'IN' THEN 1 END) as in_count,
            COUNT(CASE WHEN type = 'OUT' THEN 1 END) as out_count,
            COUNT(CASE WHEN is_late = 1 THEN 1 END) as late_count,
            COUNT(CASE WHEN is_late = 0 THEN 1 END) as normal_count,
            COUNT(CASE WHEN is_late = 1 AND type = 'IN' THEN 1 END) as late_in,
            COUNT(CASE WHEN is_late = 1 AND type = 'OUT' THEN 1 END) as late_out
        ")->first();

        $total = (int) ($stats->total_movements ?? 0);
        $in = (int) ($stats->in_count ?? 0);
        $out = (int) ($stats->out_count ?? 0);
        $late = (int) ($stats->late_count ?? 0);
        $normal = (int) ($stats->normal_count ?? 0);
        $latePct = $total > 0 ? (int) round(($late / $total) * 100) : 0;

        $movements = $query->limit(100)->get();

        return [
            'student' => [
                'id' => $student->id,
                'name' => $student->name,
                'roll_number' => $student->roll_number,
                'student_id' => $student->student_id,
                'program' => $student->program,
                'department' => $student->department,
                'year' => $student->year,
                'current_status' => $student->current_status,
            ],
            'total_movements' => $total,
            'late_movements_count' => $late,
            'normal_movements_count' => $normal,
            'in_count' => $in,
            'out_count' => $out,
            'late_rate_percentage' => $latePct,
            'summary' => [
                'total_movements' => $total,
                'in' => $in,
                'out' => $out,
                'late' => $late,
                'normal' => $normal,
                'late_rate_percentage' => $latePct,
                'late_in' => (int) ($stats->late_in ?? 0),
                'late_out' => (int) ($stats->late_out ?? 0),
            ],
            'movements' => $movements,
        ];
    }
}
