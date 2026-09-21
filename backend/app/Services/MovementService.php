<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\GateSession;
use App\Models\Movement;
use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class MovementService
{
    public function __construct(
        protected AuditService $auditService
    ) {}

    /**
     * Record a student gate movement (IN / OUT) within an ACID transaction with row-locking.
     */
    public function recordMovement(
        Student $studentModel,
        string $sessionToken,
        string $type,
        array $data = [],
        ?string $clientRequestId = null,
        ?User $securityUser = null
    ): Movement {
        $type = strtoupper($type);
        if (!in_array($type, [Movement::TYPE_IN, Movement::TYPE_OUT], true)) {
            throw new InvalidArgumentException('Invalid movement type. Must be IN or OUT.');
        }

        // Idempotency check: Return existing movement if clientRequestId was already processed
        if (!empty($clientRequestId)) {
            $existing = Movement::where('client_request_id', $clientRequestId)->first();
            if ($existing) {
                return $existing;
            }
        }

        $movement = DB::transaction(function () use ($studentModel, $sessionToken, $type, $data, $clientRequestId, $securityUser) {
            // Lock student row to prevent concurrent race condition state transitions
            $student = Student::where('id', $studentModel->id)->lockForUpdate()->first();
            if (!$student) {
                throw new InvalidArgumentException('Student record not found.');
            }

            if (!$student->isActive()) {
                throw new InvalidArgumentException('Only verified and active students are authorized to record gate movements.');
            }

            // Lock and verify the gate session
            $gateSession = GateSession::where('session_token', $sessionToken)->lockForUpdate()->first();
            if (!$gateSession) {
                throw new InvalidArgumentException('Invalid gate session. Please scan the temporary Gate QR.');
            }

            if ($gateSession->student_id !== $student->id) {
                throw new InvalidArgumentException('Gate session does not belong to this student.');
            }

            if ($gateSession->status !== GateSession::STATUS_PENDING) {
                throw new InvalidArgumentException('This gate session has already been used or expired. Please scan QR again.');
            }

            if ($gateSession->expires_at->isPast()) {
                $gateSession->update(['status' => GateSession::STATUS_EXPIRED]);
                throw new InvalidArgumentException('Your gate session has expired. Please scan the QR again.');
            }

            // Ensure the gate is staffed by an authorized security officer on active duty
            $activeDuty = \App\Models\SecurityDutySession::where('gate_id', $gateSession->gate_id)
                ->where('status', \App\Models\SecurityDutySession::STATUS_ACTIVE)
                ->whereNull('ended_at')
                ->first();

            if (!$activeDuty) {
                throw new InvalidArgumentException('Cannot record movement: No authorized security officer is currently on active duty at this gate.');
            }

            // Validate State Transitions
            if ($type === Movement::TYPE_IN) {
                if ($student->isInside()) {
                    throw new InvalidArgumentException('You are already marked INSIDE. You cannot record another IN movement.');
                }
            } else {
                // OUT
                if ($student->isOutside()) {
                    throw new InvalidArgumentException('You are already marked OUTSIDE. You cannot record another OUT movement.');
                }

                // OUT requires destination and purpose
                if (empty($data['destination'])) {
                    throw new InvalidArgumentException('Destination is required when exiting the gate.');
                }
                if (empty($data['purpose'])) {
                    throw new InvalidArgumentException('Purpose of visit is required when exiting the gate.');
                }

                // If vehicle is present, vehicle number is strictly required
                if (!empty($data['vehicle_present']) && empty($data['vehicle_number'])) {
                    throw new InvalidArgumentException('Vehicle registration number is required when exiting in a vehicle.');
                }
            }

            $serverTimestamp = now('Asia/Kolkata');
            $movementUuid = Str::uuid()->toString();
            $verificationCode = 'SG-' . strtoupper(Str::random(6));

            $isLate = Movement::isLateTimestamp($serverTimestamp);
            $lateWindowDate = Movement::calculateLateWindowDate($serverTimestamp);
            $isDayScholarAfterHours = Movement::isDayScholarAfterHours($student, $serverTimestamp);

            $movement = Movement::create([
                'movement_uuid' => $movementUuid,
                'verification_code' => $verificationCode,
                'student_id' => $student->id,
                'gate_id' => $gateSession->gate_id,
                'security_user_id' => $securityUser?->id ?? $activeDuty->user_id,
                'type' => $type,
                'movement_source' => Movement::SOURCE_QR,
                'is_late' => $isLate,
                'late_window_date' => $lateWindowDate,
                'day_scholar_after_hours' => $isDayScholarAfterHours,
                'vehicle_present' => !empty($data['vehicle_present']),
                'vehicle_number' => !empty($data['vehicle_present']) ? trim($data['vehicle_number'] ?? '') : null,
                'destination' => $data['destination'] ?? null,
                'destination_other' => $data['destination_other'] ?? null,
                'purpose' => $data['purpose'] ?? null,
                'purpose_other' => $data['purpose_other'] ?? null,
                'client_request_id' => $clientRequestId,
                'server_timestamp' => $serverTimestamp,
            ]);

            // Update student status
            $student->update([
                'current_status' => $type === Movement::TYPE_IN ? Student::STATE_INSIDE : Student::STATE_OUTSIDE,
                'last_gate_id' => $gateSession->gate_id,
                'last_movement_at' => $serverTimestamp,
            ]);

            // Consume gate session
            $gateSession->consume();

            // Audit log
            $this->auditService->log(
                action: 'MOVEMENT_' . $type,
                module: 'MOVEMENT',
                status: AuditLog::STATUS_SUCCESS,
                metadata: [
                    'verification_code' => $verificationCode,
                    'gate_id' => $gateSession->gate_id,
                    'type' => $type,
                    'destination' => $data['destination'] ?? null,
                    'student_roll' => $student->roll_number,
                ],
                entityType: Movement::class,
                entityId: (string) $movement->id,
                user: $student->user
            );

            return $movement;
        });

        // Dispatch real-time movement event after transaction commits successfully
        try {
            event(new \App\Events\StudentMovementRecorded($movement));
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('StudentMovementRecorded event dispatch failure: ' . $e->getMessage());
        }

        return $movement;
    }

    /**
     * Record a security-assisted manual student gate movement (IN / OUT).
     * Authoritatively validates guard duty session, gate assignment, student account status,
     * enforces ACID pessimistic row-locking on the student, validates state transitions,
     * handles idempotency, creates immutable audit trail, and dispatches real-time event.
     */
    public function recordManualMovement(
        User $guardUser,
        Student $studentModel,
        string $type,
        array $data = [],
        ?string $clientRequestId = null
    ): Movement {
        $type = strtoupper($type);
        if (!in_array($type, [Movement::TYPE_IN, Movement::TYPE_OUT], true)) {
            throw new InvalidArgumentException('Invalid movement type. Must be IN or OUT.');
        }

        // 1. Authenticated user is SECURITY
        if (!$guardUser->isSecurity()) {
            throw new InvalidArgumentException('Only authorized security officers can manually record gate movements.');
        }

        // 2. Guard account is ACTIVE
        if (!$guardUser->isActive()) {
            throw new InvalidArgumentException('Your security officer account is not active.');
        }

        // 3. Guard has an ACTIVE duty session & 4. assigned gate & 5. active gate
        $dutyService = app(DutySessionService::class);
        $activeDuty = $dutyService->getActiveSession($guardUser->id);

        if (!$activeDuty || !$activeDuty->isActive()) {
            throw new InvalidArgumentException('Cannot record manual movement: Security officer does not have an active duty session.');
        }

        if (!$activeDuty->gate_id || !$activeDuty->gate || !$activeDuty->gate->isActive()) {
            throw new InvalidArgumentException('Cannot record manual movement: Security officer is not assigned to an active gate.');
        }

        // Idempotency check: Return existing movement if clientRequestId was already processed
        if (!empty($clientRequestId)) {
            $existing = Movement::where('client_request_id', $clientRequestId)->first();
            if ($existing) {
                return $existing;
            }
        }

        $movement = DB::transaction(function () use ($studentModel, $guardUser, $activeDuty, $type, $data, $clientRequestId) {
            // Lock student row to prevent concurrent race condition state transitions
            $student = Student::where('id', $studentModel->id)->lockForUpdate()->first();
            if (!$student) {
                throw new InvalidArgumentException('Student record not found.');
            }

            if (!$student->isActive()) {
                if ($student->isPending()) {
                    throw new InvalidArgumentException('Cannot record movement: Student account is pending approval by University Administration.');
                }
                if ($student->isSuspended()) {
                    throw new InvalidArgumentException('Cannot record movement: Student gate privileges are suspended by administration.');
                }
                if ($student->isRejected()) {
                    throw new InvalidArgumentException('Cannot record movement: Student registration was rejected by administration.');
                }
                throw new InvalidArgumentException('Cannot record movement: Student account is inactive.');
            }

            // Validate State Transitions
            if ($type === Movement::TYPE_IN) {
                if ($student->isInside()) {
                    throw new InvalidArgumentException('Student is already marked INSIDE. You cannot record another IN movement.');
                }
            } else {
                // OUT
                if ($student->isOutside()) {
                    throw new InvalidArgumentException('Student is already marked OUTSIDE. You cannot record another OUT movement.');
                }

                // OUT requires destination and purpose
                if (empty($data['destination'])) {
                    throw new InvalidArgumentException('Destination is required when exiting the gate.');
                }
                if (empty($data['purpose'])) {
                    throw new InvalidArgumentException('Purpose of visit is required when exiting the gate.');
                }

                // If vehicle is present, vehicle number is strictly required
                if (!empty($data['vehicle_present']) && empty($data['vehicle_number'])) {
                    throw new InvalidArgumentException('Vehicle registration number is required when exiting in a vehicle.');
                }
            }

            $serverTimestamp = now('Asia/Kolkata');
            $movementUuid = Str::uuid()->toString();
            $verificationCode = 'SG-' . strtoupper(Str::random(6));

            $isLate = Movement::isLateTimestamp($serverTimestamp);
            $lateWindowDate = Movement::calculateLateWindowDate($serverTimestamp);
            $isDayScholarAfterHours = Movement::isDayScholarAfterHours($student, $serverTimestamp);

            $movement = Movement::create([
                'movement_uuid' => $movementUuid,
                'verification_code' => $verificationCode,
                'student_id' => $student->id,
                'gate_id' => $activeDuty->gate_id,
                'security_user_id' => $guardUser->id,
                'type' => $type,
                'movement_source' => Movement::SOURCE_SECURITY_MANUAL,
                'is_late' => $isLate,
                'late_window_date' => $lateWindowDate,
                'day_scholar_after_hours' => $isDayScholarAfterHours,
                'vehicle_present' => !empty($data['vehicle_present']),
                'vehicle_number' => !empty($data['vehicle_present']) ? trim($data['vehicle_number'] ?? '') : null,
                'destination' => $data['destination'] ?? null,
                'destination_other' => $data['destination_other'] ?? null,
                'purpose' => $data['purpose'] ?? null,
                'purpose_other' => $data['purpose_other'] ?? null,
                'client_request_id' => $clientRequestId,
                'server_timestamp' => $serverTimestamp,
            ]);

            // Update student status
            $student->update([
                'current_status' => $type === Movement::TYPE_IN ? Student::STATE_INSIDE : Student::STATE_OUTSIDE,
                'last_gate_id' => $activeDuty->gate_id,
                'last_movement_at' => $serverTimestamp,
            ]);

            // Audit log
            $this->auditService->log(
                action: 'SECURITY_MANUAL_' . $type,
                module: 'MOVEMENT',
                status: AuditLog::STATUS_SUCCESS,
                metadata: [
                    'verification_code' => $verificationCode,
                    'gate_id' => $activeDuty->gate_id,
                    'guard_id' => $guardUser->id,
                    'type' => $type,
                    'destination' => $data['destination'] ?? null,
                    'purpose' => $data['purpose'] ?? null,
                    'student_roll' => $student->roll_number,
                    'movement_source' => Movement::SOURCE_SECURITY_MANUAL,
                ],
                entityType: Movement::class,
                entityId: (string) $movement->id,
                user: $guardUser
            );

            return $movement;
        });

        // Dispatch real-time movement event after transaction commits successfully
        try {
            event(new \App\Events\StudentMovementRecorded($movement));
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('StudentMovementRecorded event dispatch failure: ' . $e->getMessage());
        }

        return $movement;
    }
}
