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
use App\Services\ExcelExportService;
use App\Services\MovementQueryService;
use App\Services\ProfilePhotoService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected DutySessionService $dutySessionService,
        protected AuditService $auditService,
        protected MovementQueryService $movementQueryService,
        protected ExcelExportService $excelExportService,
        protected ProfilePhotoService $profilePhotoService
    ) {}

    /**
     * Admin Dashboard Summary KPI metrics.
     */
    public function dashboard(): JsonResponse
    {
        $todayStart = Carbon::now('Asia/Kolkata')->startOfDay();
        $todayEnd = Carbon::now('Asia/Kolkata')->endOfDay();

        // 1. Single aggregate query for student presence status counts
        $studentCounts = Student::selectRaw("
            COUNT(CASE WHEN current_status = ? THEN 1 END) as inside_count,
            COUNT(CASE WHEN current_status = ? THEN 1 END) as outside_count
        ", [Student::STATE_INSIDE, Student::STATE_OUTSIDE])->first();

        $studentsInside = (int) ($studentCounts->inside_count ?? 0);
        $studentsOutside = (int) ($studentCounts->outside_count ?? 0);

        // 2. Active duty sessions (eager loaded)
        $activeDutySessions = SecurityDutySession::with(['user', 'gate'])
            ->where('status', SecurityDutySession::STATUS_ACTIVE)
            ->get();

        // 3. Single aggregated query for today's movements across all gates (eliminates 2N queries)
        $movementAggregates = Movement::whereBetween('server_timestamp', [$todayStart, $todayEnd])
            ->selectRaw("
                gate_id,
                COUNT(CASE WHEN type = ? THEN 1 END) as in_cnt,
                COUNT(CASE WHEN type = ? THEN 1 END) as out_cnt
            ", [Movement::TYPE_IN, Movement::TYPE_OUT])
            ->groupBy('gate_id')
            ->get()
            ->keyBy('gate_id');

        $todayIn = 0;
        $todayOut = 0;
        foreach ($movementAggregates as $agg) {
            $todayIn += (int) $agg->in_cnt;
            $todayOut += (int) $agg->out_cnt;
        }

        $gates = Gate::all();
        $gateStats = [];
        foreach ($gates as $g) {
            $gateAgg = $movementAggregates->get($g->id);
            $gateIn = (int) ($gateAgg->in_cnt ?? 0);
            $gateOut = (int) ($gateAgg->out_cnt ?? 0);

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

        if ($request->has('category') && !empty($request->category)) {
            $cat = $request->category;
            $query->where(function ($sub) use ($cat) {
                $sub->where('category', $cat)->orWhere('student_type', $cat);
            });
        }

        if ($request->has('student_type') && !empty($request->student_type)) {
            $st = $request->student_type;
            $query->where(function ($sub) use ($st) {
                $sub->where('student_type', $st)->orWhere('category', $st);
            });
        }

        $students = $query->orderByDesc('id')->paginate(25);
        $students->getCollection()->each->append('profile_photo_url');

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
            'category' => ['nullable', 'string', 'in:HOSTELER,HOSTELLER,DAY_SCHOLAR'],
            'student_type' => ['nullable', 'string', 'in:HOSTELLER,DAY_SCHOLAR'],
            'phone_number' => ['nullable', 'string', 'max:30'],
            'program' => ['nullable', 'string', 'max:100'],
            'department' => ['nullable', 'string', 'max:100'],
            'year' => ['nullable', 'integer', 'min:1', 'max:6'],
            'semester' => ['nullable', 'integer', 'min:1', 'max:12'],
            'batch' => ['nullable', 'string', 'max:50'],
        ]);

        $studentType = $validated['student_type'] ?? ($validated['category'] === 'DAY_SCHOLAR' ? 'DAY_SCHOLAR' : 'HOSTELLER');

        return DB::transaction(function () use ($validated, $studentType, $request) {
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
                'student_type' => $studentType,
                'category' => ($studentType === 'DAY_SCHOLAR') ? Student::CATEGORY_DAY_SCHOLAR : Student::CATEGORY_HOSTELER,
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
                    'student_type' => $student->student_type,
                    'category' => $student->category,
                ],
                entityType: Student::class,
                entityId: (string) $student->id,
                user: $request->user()
            );

            return $this->success($student, 'Student created successfully.');
        });
    }

    /**
     * Update student administrative record (category, student_type, contact info, academic details).
     */
    public function updateStudent(Request $request, Student $student): JsonResponse
    {
        $validated = $request->validate([
            'category' => ['nullable', 'string', 'in:HOSTELER,HOSTELLER,DAY_SCHOLAR'],
            'student_type' => ['nullable', 'string', 'in:HOSTELLER,DAY_SCHOLAR'],
            'phone_number' => ['nullable', 'string', 'max:30'],
            'program' => ['nullable', 'string', 'max:100'],
            'department' => ['nullable', 'string', 'max:100'],
            'year' => ['nullable', 'integer', 'min:1', 'max:6'],
            'semester' => ['nullable', 'integer', 'min:1', 'max:12'],
            'batch' => ['nullable', 'string', 'max:50'],
        ]);

        $student->update(array_filter($validated, fn ($v) => $v !== null));

        return $this->success($student->fresh(), 'Student updated successfully.');
    }

    /**
     * Upload or update a student's profile photo as an administrator.
     */
    public function uploadStudentPhoto(Request $request, Student $student): JsonResponse
    {
        $file = $request->file('profile_photo') ?? $request->file('photo');
        if (!$file) {
            return $this->error('Please provide a profile photo file.', null, 422);
        }

        try {
            $this->profilePhotoService->processAndStore(
                file: $file,
                student: $student,
                actor: $request->user()
            );

            $student->refresh()->append('profile_photo_url');

            return $this->success([
                'profile_photo' => $student->profile_photo,
                'profile_photo_url' => $student->profile_photo_url,
                'student' => $student,
            ], 'Student profile photo uploaded successfully.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), null, 422);
        } catch (\Throwable $e) {
            return $this->error('Failed to process student photo: ' . $e->getMessage(), null, 500);
        }
    }

    /**
     * Remove a student's profile photo as an administrator.
     */
    public function deleteStudentPhoto(Request $request, Student $student): JsonResponse
    {
        try {
            $this->profilePhotoService->deletePhoto(
                student: $student,
                actor: $request->user()
            );

            $student->refresh()->append('profile_photo_url');

            return $this->success([
                'profile_photo' => null,
                'profile_photo_url' => null,
                'student' => $student,
            ], 'Student profile photo removed successfully.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->error('Failed to remove student photo: ' . $e->getMessage(), null, 500);
        }
    }

    /**
     * View full student details for administrative verification.
     */
    public function showStudent(Student $student): JsonResponse
    {
        $student->load(['user', 'lastGate'])->append('profile_photo_url');
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

        try {
            $student->loadMissing('user');
            event(new \App\Events\StudentStatusChanged('APPROVED', $student, $request->user()));
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('StudentStatusChanged event error: ' . $e->getMessage());
        }

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

        try {
            $student->loadMissing('user');
            event(new \App\Events\StudentStatusChanged('REJECTED', $student, $request->user()));
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('StudentStatusChanged event error: ' . $e->getMessage());
        }

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

        try {
            $student->loadMissing('user');
            event(new \App\Events\StudentStatusChanged('SUSPENDED', $student, $request->user()));
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('StudentStatusChanged event error: ' . $e->getMessage());
        }

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

        try {
            $student->loadMissing('user');
            event(new \App\Events\StudentStatusChanged('REACTIVATED', $student, $request->user()));
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('StudentStatusChanged event error: ' . $e->getMessage());
        }

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
        $perPage = min((int) $request->input('per_page', 30), 100);
        if ($perPage < 1) {
            $perPage = 30;
        }

        $query = $this->movementQueryService->buildQuery($request)->orderByDesc('server_timestamp');
        $movements = $query->paginate($perPage);
        $movements->getCollection()->each(fn ($m) => $m->student?->append('profile_photo_url'));

        return $this->success($movements, '1-year movement logs retrieved.');
    }

    /**
     * Export movements dataset as XLSX (default) or CSV.
     * Enforces identical filter pipeline and creates ADMIN_MOVEMENT_EXPORT audit log.
     */
    public function exportMovements(Request $request): BinaryFileResponse|StreamedResponse
    {
        $format = strtolower($request->query('format', 'xlsx'));
        $query = $this->movementQueryService->buildQuery($request)->orderByDesc('server_timestamp');

        if ($format === 'csv') {
            return $this->excelExportService->exportCsv(
                query: $query,
                filters: $request->all(),
                adminUser: $request->user(),
                ip: $request->ip(),
                userAgent: $request->header('User-Agent')
            );
        }

        return $this->excelExportService->exportXlsx(
            query: $query,
            filters: $request->all(),
            adminUser: $request->user(),
            ip: $request->ip(),
            userAgent: $request->header('User-Agent')
        );
    }

    /**
     * Legacy CSV export alias for backward compatibility.
     */
    public function exportMovementsCsv(Request $request): StreamedResponse|BinaryFileResponse
    {
        $request->merge(['format' => $request->query('format', 'csv')]);
        return $this->exportMovements($request);
    }

    /**
     * Generate comprehensive Movement Analytics Report on the filtered dataset.
     */
    public function movementReports(Request $request): JsonResponse
    {
        $analytics = $this->movementQueryService->getAnalytics($request);
        return $this->success($analytics, 'Movement analytics report generated.');
    }

    /**
     * Generate detailed student-specific movement & late-entry report.
     */
    public function studentReport(Request $request, Student $student): JsonResponse
    {
        $report = $this->movementQueryService->getStudentReport($student->id, $request);
        return $this->success($report, 'Student movement report generated.');
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
