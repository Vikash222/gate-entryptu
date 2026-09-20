<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\AuditLog;
use App\Models\Gate;
use App\Models\Movement;
use App\Models\MovementOption;
use App\Models\SecurityDutySession;
use App\Models\Student;
use App\Models\User;
use App\Services\AuditService;
use App\Services\DutySessionService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected DutySessionService $dutySessionService,
        protected AuditService $auditService
    ) {}

    /**
     * Admin Dashboard Summary KPI metrics.
     */
    public function dashboard(): JsonResponse
    {
        $todayStart = Carbon::now('Asia/Kolkata')->startOfDay();
        $todayEnd = Carbon::now('Asia/Kolkata')->endOfDay();

        $studentsInside = Student::where('current_status', Student::STATE_INSIDE)->count();
        $studentsOutside = Student::where('current_status', Student::STATE_OUTSIDE)->count();

        $todayIn = Movement::whereBetween('server_timestamp', [$todayStart, $todayEnd])
            ->where('type', Movement::TYPE_IN)
            ->count();

        $todayOut = Movement::whereBetween('server_timestamp', [$todayStart, $todayEnd])
            ->where('type', Movement::TYPE_OUT)
            ->count();

        $activeDutySessions = SecurityDutySession::with(['user', 'gate'])
            ->where('status', SecurityDutySession::STATUS_ACTIVE)
            ->get();

        $gates = Gate::all();
        $gateStats = [];
        foreach ($gates as $g) {
            $gateIn = Movement::where('gate_id', $g->id)
                ->whereBetween('server_timestamp', [$todayStart, $todayEnd])
                ->where('type', Movement::TYPE_IN)
                ->count();
            $gateOut = Movement::where('gate_id', $g->id)
                ->whereBetween('server_timestamp', [$todayStart, $todayEnd])
                ->where('type', Movement::TYPE_OUT)
                ->count();

            $gateStats[] = [
                'id' => $g->id,
                'name' => $g->name,
                'code' => $g->code,
                'status' => $g->status,
                'today_in' => $gateIn,
                'today_out' => $gateOut,
                'total_today' => $gateIn + $gateOut,
            ];
        }

        return $this->success([
            'metrics' => [
                'students_inside' => $studentsInside,
                'students_outside' => $studentsOutside,
                'total_registered_students' => $studentsInside + $studentsOutside,
                'today_in' => $todayIn,
                'today_out' => $todayOut,
                'total_today_movements' => $todayIn + $todayOut,
                'active_duty_sessions_count' => $activeDutySessions->count(),
                'max_active_duty_sessions' => DutySessionService::MAX_ACTIVE_SESSIONS,
            ],
            'active_duty_sessions' => $activeDutySessions,
            'gate_stats' => $gateStats,
            'server_time' => now('Asia/Kolkata')->toIso8601String(),
        ], 'Admin dashboard metrics loaded.');
    }

    /**
     * List Students with search and filters.
     */
    public function students(Request $request): JsonResponse
    {
        $query = Student::with('lastGate');

        if ($request->has('search') && !empty($request->search)) {
            $s = trim($request->search);
            $query->where(function ($sub) use ($s) {
                $sub->where('roll_number', 'LIKE', "%{$s}%")
                    ->orWhere('student_id', 'LIKE', "%{$s}%")
                    ->orWhere('name', 'LIKE', "%{$s}%")
                    ->orWhere('email', 'LIKE', "%{$s}%")
                    ->orWhere('phone_number', 'LIKE', "%{$s}%");
            });
        }

        if ($request->has('status') && !empty($request->status)) {
            $query->where('status', $request->status);
        }

        if ($request->has('current_status') && !empty($request->current_status)) {
            $query->where('current_status', $request->current_status);
        }

        $students = $query->orderByDesc('id')->paginate(25);

        return $this->success($students, 'Students retrieved.');
    }

    /**
     * Create a new student and underlying user login.
     */
    public function storeStudent(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'student_id' => ['required', 'string', 'max:50', 'unique:students,student_id'],
            'roll_number' => ['required', 'string', 'max:50', 'unique:students,roll_number'],
            'phone_number' => ['nullable', 'string', 'max:30'],
            'program' => ['nullable', 'string', 'max:100'],
            'department' => ['nullable', 'string', 'max:100'],
            'year' => ['nullable', 'integer', 'min:1', 'max:6'],
            'semester' => ['nullable', 'integer', 'min:1', 'max:12'],
            'batch' => ['nullable', 'string', 'max:50'],
        ]);

        return DB::transaction(function () use ($validated, $request) {
            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
                'role' => User::ROLE_STUDENT,
                'status' => User::STATUS_ACTIVE,
            ]);

            $student = Student::create([
                'user_id' => $user->id,
                'student_id' => $validated['student_id'],
                'roll_number' => $validated['roll_number'],
                'name' => $validated['name'],
                'email' => $validated['email'],
                'phone_number' => $validated['phone_number'] ?? null,
                'program' => $validated['program'] ?? null,
                'department' => $validated['department'] ?? null,
                'year' => $validated['year'] ?? null,
                'semester' => $validated['semester'] ?? null,
                'batch' => $validated['batch'] ?? null,
                'status' => Student::STATUS_ACTIVE,
                'current_status' => Student::STATE_INSIDE,
            ]);

            $this->auditService->log(
                action: 'STUDENT_CREATED',
                module: 'ADMIN',
                status: AuditLog::STATUS_SUCCESS,
                metadata: [
                    'student_id' => $student->student_id,
                    'roll_number' => $student->roll_number,
                ],
                entityType: Student::class,
                entityId: (string) $student->id,
                user: $request->user()
            );

            return $this->success($student, 'Student created successfully.');
        });
    }

    /**
     * View full student details for administrative verification.
     */
    public function showStudent(Student $student): JsonResponse
    {
        $student->load(['user', 'lastGate']);
        return $this->success($student, 'Student details retrieved.');
    }

    /**
     * Approve a pending student registration.
     */
    public function approveStudent(Request $request, Student $student): JsonResponse
    {
        DB::transaction(function () use ($student, $request) {
            $student->update(['status' => Student::STATUS_ACTIVE]);
            $student->user?->update(['status' => User::STATUS_ACTIVE]);

            $this->auditService->log(
                action: 'STUDENT_APPROVED',
                module: 'ADMIN',
                status: AuditLog::STATUS_SUCCESS,
                metadata: [
                    'student_id' => $student->id,
                    'roll_number' => $student->roll_number,
                    'email' => $student->email,
                ],
                entityType: Student::class,
                entityId: (string) $student->id,
                user: $request->user()
            );
        });

        return $this->success($student->fresh(), 'Student approved successfully. Gate entry access is now active.');
    }

    /**
     * Reject a student registration.
     */
    public function rejectStudent(Request $request, Student $student): JsonResponse
    {
        DB::transaction(function () use ($student, $request) {
            $student->update(['status' => Student::STATUS_REJECTED]);
            $student->user?->update(['status' => User::STATUS_REJECTED]);

            $this->auditService->log(
                action: 'STUDENT_REJECTED',
                module: 'ADMIN',
                status: AuditLog::STATUS_SUCCESS,
                metadata: [
                    'student_id' => $student->id,
                    'roll_number' => $student->roll_number,
                    'email' => $student->email,
                ],
                entityType: Student::class,
                entityId: (string) $student->id,
                user: $request->user()
            );
        });

        return $this->success($student->fresh(), 'Student registration rejected.');
    }

    /**
     * Suspend a student's gate entry access.
     */
    public function suspendStudent(Request $request, Student $student): JsonResponse
    {
        DB::transaction(function () use ($student, $request) {
            $student->update(['status' => Student::STATUS_SUSPENDED]);
            $student->user?->update(['status' => User::STATUS_SUSPENDED]);

            $this->auditService->log(
                action: 'STUDENT_SUSPENDED',
                module: 'ADMIN',
                status: AuditLog::STATUS_SUCCESS,
                metadata: [
                    'student_id' => $student->id,
                    'roll_number' => $student->roll_number,
                    'email' => $student->email,
                ],
                entityType: Student::class,
                entityId: (string) $student->id,
                user: $request->user()
            );
        });

        return $this->success($student->fresh(), 'Student account suspended. Gate access immediately revoked.');
    }

    /**
     * Reactivate a suspended or inactive student.
     */
    public function reactivateStudent(Request $request, Student $student): JsonResponse
    {
        DB::transaction(function () use ($student, $request) {
            $student->update(['status' => Student::STATUS_ACTIVE]);
            $student->user?->update(['status' => User::STATUS_ACTIVE]);

            $this->auditService->log(
                action: 'STUDENT_REACTIVATED',
                module: 'ADMIN',
                status: AuditLog::STATUS_SUCCESS,
                metadata: [
                    'student_id' => $student->id,
                    'roll_number' => $student->roll_number,
                    'email' => $student->email,
                ],
                entityType: Student::class,
                entityId: (string) $student->id,
                user: $request->user()
            );
        });

        return $this->success($student->fresh(), 'Student account reactivated. Gate access restored.');
    }

    /**
     * List Security Guard accounts with live active duty state, gate assignment, and timestamps.
     */
    public function securityGuards(): JsonResponse
    {
        $guards = User::where('role', User::ROLE_SECURITY)
            ->with(['securityDutySessions' => function ($q) {
                $q->with('gate')->orderByDesc('id');
            }])
            ->orderBy('name')
            ->get();

        $data = $guards->map(function ($guard) {
            $activeSession = $guard->securityDutySessions->firstWhere('status', SecurityDutySession::STATUS_ACTIVE);
            $latestSession = $guard->securityDutySessions->first();

            $isOnDuty = $activeSession !== null && $activeSession->gate !== null && $activeSession->gate->isActive();
            $gate = $isOnDuty ? $activeSession->gate : null;

            return [
                'id' => $guard->id,
                'name' => $guard->name,
                'email' => $guard->email,
                'account_status' => $guard->status,
                'is_account_active' => $guard->isActive(),
                'duty_status' => $isOnDuty ? 'ACTIVE' : 'OFF_DUTY',
                'is_on_duty' => $isOnDuty,
                'current_gate' => $gate ? [
                    'id' => $gate->id,
                    'name' => $gate->name,
                    'code' => $gate->code,
                ] : null,
                'current_gate_name' => $gate?->name ?? '-',
                'duty_started_at' => $isOnDuty ? $activeSession->started_at?->toIso8601String() : null,
                'duty_ended_at' => (!$isOnDuty && $latestSession?->ended_at) ? $latestSession->ended_at->toIso8601String() : null,
                'active_session' => $isOnDuty ? [
                    'id' => $activeSession->id,
                    'gate_id' => $activeSession->gate_id,
                    'gate_name' => $activeSession->gate->name,
                    'gate_code' => $activeSession->gate->code,
                    'started_at' => $activeSession->started_at->toIso8601String(),
                ] : null,
                'last_session' => $latestSession ? [
                    'id' => $latestSession->id,
                    'gate_id' => $latestSession->gate_id,
                    'gate_name' => $latestSession->gate?->name,
                    'status' => $latestSession->status,
                    'started_at' => $latestSession->started_at->toIso8601String(),
                    'ended_at' => $latestSession->ended_at?->toIso8601String(),
                ] : null,
                'security_duty_sessions' => $isOnDuty ? [$activeSession] : [],
            ];
        });

        return $this->success($data, 'Security guards retrieved.');
    }

    /**
     * Create a new Security Guard account.
     */
    public function storeSecurityGuard(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
        ]);

        $guard = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'role' => User::ROLE_SECURITY,
            'status' => User::STATUS_ACTIVE,
        ]);

        $this->auditService->log(
            action: 'SECURITY_ACCOUNT_CREATED',
            module: 'ADMIN',
            status: AuditLog::STATUS_SUCCESS,
            metadata: ['guard_email' => $guard->email],
            entityType: User::class,
            entityId: (string) $guard->id,
            user: $request->user()
        );

        return $this->success($guard, 'Security guard account created successfully.');
    }

    /**
     * Force end an active Security duty session (Admin privilege).
     */
    public function forceEndDutySession(SecurityDutySession $session, Request $request): JsonResponse
    {
        if (!$session->isActive()) {
            return $this->error('Duty session is already ended.', null, 422);
        }

        $this->dutySessionService->endDuty($session, $request->user());

        return $this->success(null, 'Duty session forcefully ended. Slot released.');
    }

    /**
     * Generate an Admin OTP for duty activation.
     */
    public function generateDutyOtp(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'gate_id' => ['nullable', 'exists:gates,id'],
            'security_user_id' => ['nullable', 'exists:users,id'],
            'ttl_minutes' => ['nullable', 'integer', 'min:5', 'max:60'],
        ]);

        $otpData = $this->dutySessionService->generateAdminOtp(
            admin: $request->user(),
            gateId: $validated['gate_id'] ?? null,
            securityUserId: $validated['security_user_id'] ?? null,
            ttlMinutes: $validated['ttl_minutes'] ?? 15
        );

        return $this->success($otpData, 'Admin Duty Activation OTP generated.');
    }

    /**
     * List all Gates.
     */
    public function gates(): JsonResponse
    {
        $gates = Gate::orderBy('id')->get();
        return $this->success($gates, 'Gates retrieved.');
    }

    /**
     * Create a new gate.
     */
    public function storeGate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'code' => ['required', 'string', 'max:50', 'unique:gates,code'],
            'location' => ['nullable', 'string', 'max:150'],
            'description' => ['nullable', 'string'],
        ]);

        $gate = Gate::create([
            'name' => $validated['name'],
            'code' => strtoupper($validated['code']),
            'status' => Gate::STATUS_ACTIVE,
            'location' => $validated['location'] ?? null,
            'description' => $validated['description'] ?? null,
        ]);

        $this->auditService->log(
            action: 'GATE_CREATED',
            module: 'ADMIN',
            status: AuditLog::STATUS_SUCCESS,
            metadata: ['gate_name' => $gate->name, 'gate_code' => $gate->code],
            entityType: Gate::class,
            entityId: (string) $gate->id,
            user: $request->user()
        );

        return $this->success($gate, 'Gate created successfully.');
    }

    /**
     * Admin Movement History: Up to 1 year of movement data with full multi-parameter filtering.
     */
    public function movements(Request $request): JsonResponse
    {
        $oneYearLimit = Carbon::now('Asia/Kolkata')->subYear()->startOfDay();

        $query = Movement::with(['student', 'gate', 'securityUser'])
            ->where('server_timestamp', '>=', $oneYearLimit); // 1-year boundary

        if ($request->has('gate_id') && !empty($request->gate_id)) {
            $query->where('gate_id', $request->gate_id);
        }

        if ($request->has('type') && in_array(strtoupper($request->type), ['IN', 'OUT'])) {
            $query->where('type', strtoupper($request->type));
        }

        if ($request->has('start_date') && !empty($request->start_date)) {
            $start = Carbon::parse($request->start_date, 'Asia/Kolkata')->startOfDay();
            $query->where('server_timestamp', '>=', $start);
        }

        if ($request->has('end_date') && !empty($request->end_date)) {
            $end = Carbon::parse($request->end_date, 'Asia/Kolkata')->endOfDay();
            $query->where('server_timestamp', '<=', $end);
        }

        if ($request->has('destination') && !empty($request->destination)) {
            $query->where('destination', $request->destination);
        }

        if ($request->has('purpose') && !empty($request->purpose)) {
            $query->where('purpose', $request->purpose);
        }

        if ($request->has('vehicle_present') && $request->vehicle_present !== '') {
            $query->where('vehicle_present', filter_var($request->vehicle_present, FILTER_VALIDATE_BOOLEAN));
        }

        if ($request->has('search') && !empty($request->search)) {
            $search = trim($request->search);
            $query->where(function ($sub) use ($search) {
                $sub->where('verification_code', 'LIKE', "%{$search}%")
                    ->orWhere('vehicle_number', 'LIKE', "%{$search}%")
                    ->orWhereHas('student', function ($sq) use ($search) {
                        $sq->where('roll_number', 'LIKE', "%{$search}%")
                            ->orWhere('name', 'LIKE', "%{$search}%")
                            ->orWhere('student_id', 'LIKE', "%{$search}%");
                    });
            });
        }

        $movements = $query->orderByDesc('server_timestamp')->paginate(30);

        return $this->success($movements, '1-year movement logs retrieved.');
    }

    /**
     * Export movements to CSV.
     */
    public function exportMovementsCsv(Request $request): StreamedResponse
    {
        $oneYearLimit = Carbon::now('Asia/Kolkata')->subYear()->startOfDay();

        $query = Movement::with(['student', 'gate'])
            ->where('server_timestamp', '>=', $oneYearLimit)
            ->orderByDesc('server_timestamp');

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="smartgate-movements-' . date('Y-m-d') . '.csv"',
        ];

        return response()->stream(function () use ($query) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, [
                'Verification Code',
                'Movement Type',
                'Student Name',
                'Roll Number',
                'Student ID',
                'Gate',
                'Destination',
                'Purpose',
                'In Vehicle',
                'Vehicle Number',
                'Server Timestamp (IST)',
            ]);

            $query->chunk(200, function ($records) use ($handle) {
                foreach ($records as $m) {
                    fputcsv($handle, [
                        $m->verification_code,
                        $m->type,
                        $m->student?->name ?? 'N/A',
                        $m->student?->roll_number ?? 'N/A',
                        $m->student?->student_id ?? 'N/A',
                        $m->gate?->name ?? 'N/A',
                        $m->destination ?? '-',
                        $m->purpose ?? '-',
                        $m->vehicle_present ? 'YES' : 'NO',
                        $m->vehicle_number ?? '-',
                        $m->server_timestamp->format('Y-m-d H:i:s'),
                    ]);
                }
            });

            fclose($handle);
        }, 200, $headers);
    }

    /**
     * Immutable Audit Logs browser.
     */
    public function auditLogs(Request $request): JsonResponse
    {
        $query = AuditLog::with('user');

        if ($request->has('module') && !empty($request->module)) {
            $query->where('module', $request->module);
        }

        if ($request->has('action') && !empty($request->action)) {
            $query->where('action', $request->action);
        }

        if ($request->has('status') && !empty($request->status)) {
            $query->where('status', $request->status);
        }

        $logs = $query->orderByDesc('id')->paginate(30);

        return $this->success($logs, 'Audit logs retrieved.');
    }
}
