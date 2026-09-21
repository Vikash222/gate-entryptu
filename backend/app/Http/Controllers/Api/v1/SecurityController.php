<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Movement;
use App\Models\SecurityDutySession;
use App\Models\Student;
use App\Services\DutySessionService;
use App\Services\MovementService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SecurityController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected DutySessionService $dutySessionService,
        protected MovementService $movementService
    ) {}

    /**
     * Get the authenticated security guard's active duty session.
     */
    public function activeDuty(Request $request): JsonResponse
    {
        $user = $request->user();
        $session = $this->dutySessionService->getActiveSession($user->id);

        return $this->success([
            'has_active_duty' => (bool) $session,
            'duty_session' => $session ? [
                'id' => $session->id,
                'gate_id' => $session->gate_id,
                'gate_name' => $session->gate->name,
                'gate_code' => $session->gate->code,
                'started_at' => $session->started_at->toIso8601String(),
                'server_time' => now('Asia/Kolkata')->toIso8601String(),
            ] : null,
        ], 'Duty session status retrieved.');
    }

    /**
     * Activate security duty at a gate.
     * Strictly requires verified Admin Authorization OTP.
     */
    public function activateDuty(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'gate_id' => ['required', 'integer', 'exists:gates,id'],
            'admin_otp' => ['required', 'string', 'regex:/^[0-9]{6}$/'],
        ], [
            'admin_otp.required' => 'Admin authorization OTP is required to activate duty.',
            'admin_otp.regex' => 'Admin authorization OTP must be exactly 6 digits.',
        ]);

        $user = $request->user();

        try {
            $session = $this->dutySessionService->activateDuty(
                guard: $user,
                gateId: (int) $validated['gate_id'],
                adminOtp: $validated['admin_otp'],
                deviceIdentifier: $request->header('User-Agent'),
                ipAddress: $request->ip()
            );

            $session->load('gate');

            return $this->success([
                'id' => $session->id,
                'gate_id' => $session->gate_id,
                'gate_name' => $session->gate->name,
                'gate_code' => $session->gate->code,
                'started_at' => $session->started_at->toIso8601String(),
            ], 'Duty activated successfully. You may now operate the gate.');
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), null, 422);
        }
    }

    /**
     * End the current active duty session.
     */
    public function endDuty(Request $request): JsonResponse
    {
        $user = $request->user();
        $session = SecurityDutySession::where('user_id', $user->id)
            ->where('status', SecurityDutySession::STATUS_ACTIVE)
            ->first();

        if (!$session) {
            return $this->error('No active duty session to end.', null, 422);
        }

        $this->dutySessionService->endDuty($session, $user);

        return $this->success(null, 'Duty session ended successfully. Slot has been released.');
    }

    /**
     * Today's movements for Security screen. Sorted newest first.
     */
    public function todayMovements(Request $request): JsonResponse
    {
        $gateId = $request->query('gate_id');

        $query = Movement::with(['student', 'gate'])
            ->today()
            ->orderByDesc('server_timestamp');

        if (!empty($gateId)) {
            $query->where('gate_id', $gateId);
        }

        $user = $request->user();
        $activeSession = $user ? $this->dutySessionService->getActiveSession($user->id) : null;
        $hasActiveDuty = $activeSession && $activeSession->isActive();

        $movements = $query->limit(100)->get();
        if ($hasActiveDuty) {
            $movements->each(fn ($m) => $m->student?->append('profile_photo_url'));
        }

        return $this->success($movements, "Today's movements retrieved.");
    }

    /**
     * Fast search for students by Roll Number, Student ID, Name, or Phone Number.
     * Primary workflow is Roll Number with exact match prioritized.
     */
    public function searchStudent(Request $request): JsonResponse
    {
        $q = trim($request->query('roll_number', $request->query('query', $request->query('search', $request->query('q', '')))));

        if (strlen($q) < 1) {
            return $this->success([], 'Enter Roll Number or search term.');
        }

        $students = Student::with('lastGate')
            ->where(function ($sub) use ($q) {
                $sub->where('roll_number', $q)
                    ->orWhere('roll_number', 'LIKE', "{$q}%")
                    ->orWhere('roll_number', 'LIKE', "%{$q}%")
                    ->orWhere('student_id', $q)
                    ->orWhere('student_id', 'LIKE', "{$q}%")
                    ->orWhere('name', 'LIKE', "%{$q}%")
                    ->orWhere('phone_number', 'LIKE', "%{$q}%");
            })
            ->orderByRaw("CASE WHEN LOWER(roll_number) = LOWER(?) THEN 0 WHEN LOWER(student_id) = LOWER(?) THEN 1 WHEN roll_number LIKE ? THEN 2 ELSE 3 END", [$q, $q, "{$q}%"])
            ->limit(20)
            ->get();

        $user = $request->user();
        $activeSession = $user ? $this->dutySessionService->getActiveSession($user->id) : null;
        $hasActiveDuty = $activeSession && $activeSession->isActive();

        $now = now('Asia/Kolkata');
        $data = $students->map(function ($s) use ($now, $hasActiveDuty) {
            $isAfterHours = Movement::isDayScholarAfterHours($s, $now);
            return [
                'id' => $s->id,
                'student_id' => $s->student_id,
                'roll_number' => $s->roll_number,
                'name' => $s->name,
                'year' => $s->year,
                'program' => $s->program,
                'department' => $s->department,
                'category' => $s->category ?? Student::CATEGORY_HOSTELER,
                'student_type' => $s->student_type,
                'is_day_scholar' => $s->isDayScholar(),
                'day_scholar_after_hours' => $isAfterHours,
                'DAY_SCHOLAR_AFTER_HOURS' => $isAfterHours,
                'day_scholar_warning' => $isAfterHours ? 'DAY SCHOLAR — AFTER HOURS' : null,
                'status' => $s->status,
                'current_status' => $s->current_status,
                'profile_photo' => $s->profile_photo,
                'profile_photo_url' => $hasActiveDuty ? $s->profile_photo_url : null,
                'last_gate' => $s->lastGate ? [
                    'id' => $s->lastGate->id,
                    'name' => $s->lastGate->name,
                    'code' => $s->lastGate->code,
                ] : null,
                'last_movement_at' => $s->last_movement_at?->toIso8601String(),
            ];
        });

        return $this->success($data, 'Students found.');
    }

    /**
     * Record security-assisted manual student movement (IN / OUT).
     */
    public function manualMovement(Request $request): JsonResponse
    {
        $guard = $request->user();

        // 1. Ensure active duty session
        $activeSession = $this->dutySessionService->getActiveSession($guard->id);
        if (!$activeSession || !$activeSession->isActive()) {
            return $this->forbidden('Operational access denied: No active Admin-authorized duty session found.');
        }

        // 2. Ensure assigned gate is active and valid
        if (!$activeSession->gate_id || !$activeSession->gate || !$activeSession->gate->isActive()) {
            return $this->forbidden('Operational access denied: Assigned university gate is inactive or invalid.');
        }

        // 3. Security gate restriction: guard cannot submit another gate_id
        if ($request->has('gate_id') && (int) $request->input('gate_id') !== (int) $activeSession->gate_id) {
            return $this->forbidden('Operational access denied: You cannot record movements for a gate other than your assigned gate.');
        }

        $validated = $request->validate([
            'student_id' => ['required'],
            'type' => ['required', 'string', 'in:IN,OUT,in,out'],
            'destination' => ['nullable', 'string', 'max:255'],
            'destination_other' => ['nullable', 'string', 'max:255'],
            'purpose' => ['nullable', 'string', 'max:255'],
            'purpose_other' => ['nullable', 'string', 'max:255'],
            'vehicle_present' => ['nullable', 'boolean'],
            'vehicle_number' => ['nullable', 'string', 'max:50'],
            'client_request_id' => ['nullable', 'string', 'max:100'],
        ]);

        $student = is_numeric($validated['student_id'])
            ? Student::find($validated['student_id'])
            : Student::where('roll_number', trim($validated['student_id']))->orWhere('student_id', trim($validated['student_id']))->first();

        if (!$student) {
            return $this->notFound('Student record not found.');
        }

        // Check student account status
        if (!$student->isActive()) {
            if ($student->isPending()) {
                return $this->forbidden('Cannot record movement: Student account is pending approval by University Administration.');
            }
            if ($student->isSuspended()) {
                return $this->forbidden('Cannot record movement: Student gate access has been suspended by administration.');
            }
            if ($student->isRejected()) {
                return $this->forbidden('Cannot record movement: Student registration was rejected by administration.');
            }
            return $this->forbidden('Cannot record movement: Student account is not active.');
        }

        // Normalise destination & purpose
        $destination = $validated['destination'] ?? null;
        if ($destination === 'Other' && !empty($validated['destination_other'])) {
            $destination = trim($validated['destination_other']);
        }

        $purpose = $validated['purpose'] ?? null;
        if ($purpose === 'Other' && !empty($validated['purpose_other'])) {
            $purpose = trim($validated['purpose_other']);
        }

        $movementData = [
            'destination' => $destination,
            'destination_other' => $validated['destination_other'] ?? null,
            'purpose' => $purpose,
            'purpose_other' => $validated['purpose_other'] ?? null,
            'vehicle_present' => !empty($validated['vehicle_present']),
            'vehicle_number' => $validated['vehicle_number'] ?? null,
        ];

        try {
            $movement = $this->movementService->recordManualMovement(
                guardUser: $guard,
                studentModel: $student,
                type: strtoupper($validated['type']),
                data: $movementData,
                clientRequestId: $validated['client_request_id'] ?? null
            );

            $movement->loadMissing(['student', 'gate']);

            return $this->success([
                'verification_code' => $movement->verification_code,
                'movement_type' => $movement->type,
                'student_name' => $movement->student->name,
                'roll_number' => $movement->student->roll_number,
                'category' => $movement->student->category ?? Student::CATEGORY_HOSTELER,
                'student_type' => $movement->student->student_type,
                'is_day_scholar' => $movement->student->isDayScholar(),
                'day_scholar_after_hours' => (bool) $movement->day_scholar_after_hours,
                'DAY_SCHOLAR_AFTER_HOURS' => (bool) $movement->day_scholar_after_hours,
                'day_scholar_warning' => $movement->day_scholar_after_hours ? 'DAY SCHOLAR — AFTER HOURS' : null,
                'gate_name' => $movement->gate->name,
                'server_timestamp' => $movement->server_timestamp->toIso8601String(),
                'destination' => $movement->destination,
                'purpose' => $movement->purpose,
                'vehicle_present' => (bool) $movement->vehicle_present,
                'vehicle_number' => $movement->vehicle_number,
                'movement_source' => $movement->movement_source,
                'is_late' => (bool) $movement->is_late,
                'late_window_date' => $movement->late_window_date?->format('Y-m-d'),
                'current_status' => $movement->type === Movement::TYPE_IN ? 'INSIDE' : 'OUTSIDE',
                'profile_photo_url' => $movement->student->profile_photo_url,
            ], 'Manual ' . $movement->type . ' movement recorded successfully.');
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), null, 422);
        }
    }

    /**
     * Detailed view of a single student for Security officer.
     */
    public function showStudent(Request $request, Student $student): JsonResponse
    {
        $student->load('lastGate');

        $latestMovement = Movement::where('student_id', $student->id)
            ->with('gate')
            ->orderByDesc('server_timestamp')
            ->first();

        $now = now('Asia/Kolkata');
        $isAfterHours = Movement::isDayScholarAfterHours($student, $now);

        $user = $request->user();
        $activeSession = $user ? $this->dutySessionService->getActiveSession($user->id) : null;
        $hasActiveDuty = $activeSession && $activeSession->isActive();

        $studentData = array_merge($student->toArray(), [
            'day_scholar_after_hours' => $isAfterHours,
            'DAY_SCHOLAR_AFTER_HOURS' => $isAfterHours,
            'day_scholar_warning' => $isAfterHours ? 'DAY SCHOLAR — AFTER HOURS' : null,
            'profile_photo_url' => $hasActiveDuty ? $student->profile_photo_url : null,
        ]);

        return $this->success([
            'student' => $studentData,
            'latest_movement' => $latestMovement ? [
                'type' => $latestMovement->type,
                'server_timestamp' => $latestMovement->server_timestamp->toIso8601String(),
                'display_time' => $latestMovement->server_timestamp->format('h:i A, d M Y'),
                'gate_name' => $latestMovement->gate->name,
                'destination' => $latestMovement->destination,
                'purpose' => $latestMovement->purpose,
                'vehicle_present' => $latestMovement->vehicle_present,
                'vehicle_number' => $latestMovement->vehicle_number,
                'day_scholar_after_hours' => (bool) $latestMovement->day_scholar_after_hours,
                'DAY_SCHOLAR_AFTER_HOURS' => (bool) $latestMovement->day_scholar_after_hours,
            ] : null,
        ], 'Student details retrieved.');
    }

    /**
     * Security student 15-day movement history.
     * Strictly capped at 15 days at query level.
     */
    public function studentHistory(Student $student): JsonResponse
    {
        $movements = Movement::with('gate')
            ->where('student_id', $student->id)
            ->last15Days() // Strict 15-day SQL boundary
            ->orderByDesc('server_timestamp')
            ->limit(50)
            ->get();

        return $this->success([
            'student' => [
                'id' => $student->id,
                'name' => $student->name,
                'roll_number' => $student->roll_number,
                'current_status' => $student->current_status,
            ],
            'movements' => $movements,
        ], 'Student 15-day movement history retrieved.');
    }

    /**
     * Security general 15-day movement history with date/gate/type filters.
     * Strictly capped at 15 days at query level.
     */
    public function history(Request $request): JsonResponse
    {
        $limitDate = Carbon::now('Asia/Kolkata')->subDays(15)->startOfDay();

        $query = Movement::with(['student', 'gate'])
            ->where('server_timestamp', '>=', $limitDate); // Non-negotiable boundary

        if ($request->has('gate_id') && !empty($request->gate_id)) {
            $query->where('gate_id', $request->gate_id);
        }

        if ($request->has('type') && in_array(strtoupper($request->type), ['IN', 'OUT'])) {
            $query->where('type', strtoupper($request->type));
        }

        if ($request->has('date') && !empty($request->date)) {
            $date = Carbon::parse($request->date, 'Asia/Kolkata');
            if ($date->lt($limitDate)) {
                return $this->error('Security is restricted to viewing the previous 15 days only.', null, 403);
            }
            $query->whereDate('server_timestamp', $date->toDateString());
        }

        if ($request->has('search') && !empty($request->search)) {
            $search = $request->search;
            $query->whereHas('student', function ($s) use ($search) {
                $s->where('roll_number', 'LIKE', "%{$search}%")
                    ->orWhere('name', 'LIKE', "%{$search}%");
            });
        }

        $movements = $query->orderByDesc('server_timestamp')->paginate(30);

        return $this->success($movements, '15-day movement history retrieved.');
    }

    /**
     * Real-time Server-Sent Events (SSE) Live Movement Stream.
     * Pushes new movements in real time to connected security devices.
     */
    public function liveStream(Request $request): StreamedResponse
    {
        $lastId = (int) $request->header('Last-Event-ID', $request->query('last_id', 0));
        $user = $request->user();
        $gateId = $request->attributes->get('active_gate_id');
        
        if (!$user || !$gateId) {
            abort(403, 'Unauthorized.');
        }

        return response()->stream(function () use ($lastId, $user, $gateId) {
            // Send retry configuration to client
            echo "retry: 3000\n\n";
            ob_flush();
            flush();

            $currentLastId = $lastId;
            $iterations = 0;

            // Stream for up to 30 seconds before reconnecting (browser standard)
            while ($iterations < 10) {
                // Must re-check session inside loop in case duty is ended externally!
                $sessionActive = \App\Models\SecurityDutySession::where('user_id', $user->id)
                    ->where('gate_id', $gateId)
                    ->where('status', \App\Models\SecurityDutySession::STATUS_ACTIVE)
                    ->whereNull('ended_at')
                    ->exists();
                
                if (!$sessionActive) {
                    echo "event: close\n";
                    echo "data: duty_ended\n\n";
                    ob_flush();
                    flush();
                    break;
                }

                $newMovements = Movement::with(['student', 'gate'])
                    ->where('id', '>', $currentLastId)
                    ->where('gate_id', $gateId)
                    ->orderBy('id', 'asc')
                    ->limit(10)
                    ->get();

                if ($newMovements->isNotEmpty()) {
                    foreach ($newMovements as $mov) {
                        $currentLastId = $mov->id;
                        $payload = json_encode([
                            'id' => $mov->id,
                            'verification_code' => $mov->verification_code,
                            'type' => $mov->type,
                            'student_name' => $mov->student->name,
                            'roll_number' => $mov->student->roll_number,
                            'gate_name' => $mov->gate->name,
                            'server_timestamp' => $mov->server_timestamp->toIso8601String(),
                            'display_time' => $mov->server_timestamp->format('h:i A'),
                            'destination' => $mov->destination,
                            'purpose' => $mov->purpose,
                            'vehicle_present' => $mov->vehicle_present,
                            'vehicle_number' => $mov->vehicle_number,
                        ]);

                        echo "id: {$mov->id}\n";
                        echo "event: movement\n";
                        echo "data: {$payload}\n\n";
                    }
                    ob_flush();
                    flush();
                } else {
                    // Send heartbeat ping
                    echo ": ping\n\n";
                    ob_flush();
                    flush();
                }

                $iterations++;
                sleep(2);
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }
}
