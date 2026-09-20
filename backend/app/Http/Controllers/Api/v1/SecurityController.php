<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Movement;
use App\Models\SecurityDutySession;
use App\Models\Student;
use App\Services\DutySessionService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SecurityController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected DutySessionService $dutySessionService
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

        $movements = $query->limit(100)->get();

        return $this->success($movements, "Today's movements retrieved.");
    }

    /**
     * Fast search for students by Name, Roll Number, Student ID, or Phone Number.
     */
    public function searchStudent(Request $request): JsonResponse
    {
        $q = trim($request->query('query', ''));

        if (strlen($q) < 2) {
            return $this->success([], 'Enter at least 2 characters to search.');
        }

        $students = Student::with('lastGate')
            ->where(function ($sub) use ($q) {
                $sub->where('roll_number', 'LIKE', "%{$q}%")
                    ->orWhere('student_id', 'LIKE', "%{$q}%")
                    ->orWhere('name', 'LIKE', "%{$q}%")
                    ->orWhere('phone_number', 'LIKE', "%{$q}%");
            })
            ->limit(20)
            ->get();

        return $this->success($students, 'Students found.');
    }

    /**
     * Detailed view of a single student for Security officer.
     */
    public function showStudent(Student $student): JsonResponse
    {
        $student->load('lastGate');

        $latestMovement = Movement::where('student_id', $student->id)
            ->with('gate')
            ->orderByDesc('server_timestamp')
            ->first();

        return $this->success([
            'student' => $student,
            'latest_movement' => $latestMovement ? [
                'type' => $latestMovement->type,
                'server_timestamp' => $latestMovement->server_timestamp->toIso8601String(),
                'display_time' => $latestMovement->server_timestamp->format('h:i A, d M Y'),
                'gate_name' => $latestMovement->gate->name,
                'destination' => $latestMovement->destination,
                'purpose' => $latestMovement->purpose,
                'vehicle_present' => $latestMovement->vehicle_present,
                'vehicle_number' => $latestMovement->vehicle_number,
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

        return response()->stream(function () use ($lastId) {
            // Send retry configuration to client
            echo "retry: 3000\n\n";
            ob_flush();
            flush();

            $currentLastId = $lastId;
            $iterations = 0;

            // Stream for up to 30 seconds before reconnecting (browser standard)
            while ($iterations < 10) {
                $newMovements = Movement::with(['student', 'gate'])
                    ->where('id', '>', $currentLastId)
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
