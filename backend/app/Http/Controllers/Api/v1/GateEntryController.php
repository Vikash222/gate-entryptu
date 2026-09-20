<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\AuditLog;
use App\Models\GateSession;
use App\Models\MovementOption;
use App\Services\AuditService;
use App\Services\MovementService;
use App\Services\QrTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use InvalidArgumentException;

class GateEntryController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected QrTokenService $qrTokenService,
        protected MovementService $movementService,
        protected AuditService $auditService
    ) {}

    /**
     * Verify scanned temporary Gate QR and establish a 3-minute Gate Session.
     */
    public function verifyQr(Request $request): JsonResponse
    {
        $user = $request->user();
        $student = $user?->student;

        if (!$student) {
            return $this->forbidden('Only registered students can verify gate entry.');
        }

        if (!$student->isActive()) {
            if ($student->isPending()) {
                return $this->forbidden('Your account registration is currently PENDING approval by University Administration. Gate Entry is locked.');
            }
            if ($student->isSuspended()) {
                return $this->forbidden('Your student gate access has been SUSPENDED by administration.');
            }
            if ($student->isRejected()) {
                return $this->forbidden('Your student registration was REJECTED by administration.');
            }
            return $this->forbidden('Only verified ACTIVE students can use Gate Entry.');
        }

        $validated = $request->validate([
            'qr_payload' => ['required'],
        ]);

        try {
            $qrData = $this->qrTokenService->verifyToken($validated['qr_payload']);
            $gate = $qrData['gate'];

            // Invalidate any prior pending sessions for this student
            GateSession::where('student_id', $student->id)
                ->where('status', GateSession::STATUS_PENDING)
                ->update(['status' => GateSession::STATUS_EXPIRED]);

            // Create new verified gate session (TTL 3 minutes)
            $gateSession = GateSession::create([
                'session_token' => Str::uuid()->toString(),
                'student_id' => $student->id,
                'gate_id' => $gate->id,
                'status' => GateSession::STATUS_PENDING,
                'expires_at' => now('Asia/Kolkata')->addMinutes(3),
            ]);

            $this->auditService->log(
                action: 'GATE_SESSION_CREATED',
                module: 'GATE',
                status: AuditLog::STATUS_SUCCESS,
                metadata: [
                    'gate_id' => $gate->id,
                    'gate_name' => $gate->name,
                    'session_token' => $gateSession->session_token,
                    'student_roll' => $student->roll_number,
                ],
                entityType: GateSession::class,
                entityId: (string) $gateSession->id,
                user: $user
            );

            return $this->success([
                'gate_session_token' => $gateSession->session_token,
                'gate_id' => $gate->id,
                'gate_name' => $gate->name,
                'gate_code' => $gate->code,
                'expires_at' => $gateSession->expires_at->toIso8601String(),
                'student_current_status' => $student->current_status,
            ], 'Gate verified successfully. You may now record your movement.');
        } catch (InvalidArgumentException $e) {
            $this->auditService->log(
                action: 'QR_VERIFICATION_FAILED',
                module: 'GATE',
                status: AuditLog::STATUS_FAILED,
                metadata: [
                    'error' => $e->getMessage(),
                    'student_roll' => $student->roll_number,
                ],
                user: $user
            );

            return $this->error($e->getMessage(), null, 422);
        }
    }

    /**
     * Submit IN movement (Speed-optimized: no destination/purpose required).
     */
    public function in(Request $request): JsonResponse
    {
        $user = $request->user();
        $student = $user?->student;

        if (!$student) {
            return $this->forbidden('Only registered students can record entry.');
        }

        if (!$student->isActive()) {
            if ($student->isPending()) {
                return $this->forbidden('Your account registration is currently PENDING approval by University Administration. Gate Entry is locked.');
            }
            if ($student->isSuspended()) {
                return $this->forbidden('Your student gate access has been SUSPENDED by administration.');
            }
            if ($student->isRejected()) {
                return $this->forbidden('Your student registration was REJECTED by administration.');
            }
            return $this->forbidden('Only verified ACTIVE students can record entry.');
        }

        $validated = $request->validate([
            'gate_session_token' => ['required', 'string'],
            'client_request_id' => ['nullable', 'string', 'max:100'],
        ]);

        try {
            $movement = $this->movementService->recordMovement(
                studentModel: $student,
                sessionToken: $validated['gate_session_token'],
                type: 'IN',
                data: [],
                clientRequestId: $validated['client_request_id'] ?? null
            );

            return $this->success([
                'receipt' => [
                    'verification_code' => $movement->verification_code,
                    'movement_type' => 'IN',
                    'student_name' => $student->name,
                    'roll_number' => $student->roll_number,
                    'gate_name' => $movement->gate->name,
                    'server_timestamp' => $movement->server_timestamp->toIso8601String(),
                    'display_time' => $movement->server_timestamp->format('h:i:s A, d M Y'),
                ],
                'current_status' => 'INSIDE',
            ], 'Entry successfully recorded.');
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), null, 422);
        }
    }

    /**
     * Submit OUT movement (Requires destination, purpose, and vehicle check).
     */
    public function out(Request $request): JsonResponse
    {
        $user = $request->user();
        $student = $user?->student;

        if (!$student) {
            return $this->forbidden('Only registered students can record exit.');
        }

        if (!$student->isActive()) {
            if ($student->isPending()) {
                return $this->forbidden('Your account registration is currently PENDING approval by University Administration. Gate Entry is locked.');
            }
            if ($student->isSuspended()) {
                return $this->forbidden('Your student gate access has been SUSPENDED by administration.');
            }
            if ($student->isRejected()) {
                return $this->forbidden('Your student registration was REJECTED by administration.');
            }
            return $this->forbidden('Only verified ACTIVE students can record exit.');
        }

        $validated = $request->validate([
            'gate_session_token' => ['required', 'string'],
            'destination' => ['required', 'string', 'max:150'],
            'destination_other' => ['nullable', 'string', 'max:150'],
            'purpose' => ['required', 'string', 'max:150'],
            'purpose_other' => ['nullable', 'string', 'max:150'],
            'vehicle_present' => ['required', 'boolean'],
            'vehicle_number' => ['nullable', 'string', 'max:50'],
            'client_request_id' => ['nullable', 'string', 'max:100'],
        ]);

        // If Other destination was selected, map other text
        $finalDestination = $validated['destination'] === 'Other' && !empty($validated['destination_other'])
            ? $validated['destination_other']
            : $validated['destination'];

        $finalPurpose = $validated['purpose'] === 'Other' && !empty($validated['purpose_other'])
            ? $validated['purpose_other']
            : $validated['purpose'];

        try {
            $movement = $this->movementService->recordMovement(
                studentModel: $student,
                sessionToken: $validated['gate_session_token'],
                type: 'OUT',
                data: [
                    'destination' => $finalDestination,
                    'destination_other' => $validated['destination_other'] ?? null,
                    'purpose' => $finalPurpose,
                    'purpose_other' => $validated['purpose_other'] ?? null,
                    'vehicle_present' => $validated['vehicle_present'],
                    'vehicle_number' => $validated['vehicle_number'] ?? null,
                ],
                clientRequestId: $validated['client_request_id'] ?? null
            );

            return $this->success([
                'receipt' => [
                    'verification_code' => $movement->verification_code,
                    'movement_type' => 'OUT',
                    'student_name' => $student->name,
                    'roll_number' => $student->roll_number,
                    'gate_name' => $movement->gate->name,
                    'destination' => $movement->destination,
                    'purpose' => $movement->purpose,
                    'vehicle_present' => $movement->vehicle_present,
                    'vehicle_number' => $movement->vehicle_number,
                    'server_timestamp' => $movement->server_timestamp->toIso8601String(),
                    'display_time' => $movement->server_timestamp->format('h:i:s A, d M Y'),
                ],
                'current_status' => 'OUTSIDE',
            ], 'Exit successfully recorded.');
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), null, 422);
        }
    }

    /**
     * Get configurable options for destinations and purposes.
     */
    public function options(): JsonResponse
    {
        $destinations = MovementOption::destinations()->active()->pluck('name');
        $purposes = MovementOption::purposes()->active()->pluck('name');

        return $this->success([
            'destinations' => $destinations,
            'purposes' => $purposes,
        ], 'Movement options retrieved.');
    }
}
