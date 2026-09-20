<?php

namespace App\Services;

use App\Models\AdminDutyOtp;
use App\Models\AuditLog;
use App\Models\Gate;
use App\Models\SecurityDutySession;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;

class DutySessionService
{
    public const MAX_ACTIVE_SESSIONS = 4;

    public function __construct(
        protected AuditService $auditService
    ) {}

    public const MAX_SHIFT_HOURS = 12;

    /**
     * Activate security guard duty at a gate.
     * Transactionally enforces maximum 4 concurrent active sessions AND mandatory Admin OTP.
     */
    public function activateDuty(
        User $guard,
        int $gateId,
        string $adminOtp,
        ?string $deviceIdentifier = null,
        ?string $ipAddress = null
    ): SecurityDutySession {
        $gate = Gate::find($gateId);
        if (!$gate || !$gate->isActive()) {
            throw new InvalidArgumentException('The selected gate is inactive or does not exist.');
        }

        $trimmedOtp = trim($adminOtp);
        if (empty($trimmedOtp)) {
            throw new InvalidArgumentException('Admin authorization OTP is required to activate duty.');
        }

        try {
            return DB::transaction(function () use ($guard, $gate, $trimmedOtp, $deviceIdentifier, $ipAddress) {
                // Verify and burn the Admin Duty OTP
                $otpRecord = $this->verifyAndBurnOtp($trimmedOtp, $guard->id, $gate->id);

                // Lock existing active sessions to prevent race condition exceeding 4 sessions
                $activeSessionsQuery = SecurityDutySession::where('status', SecurityDutySession::STATUS_ACTIVE)->lockForUpdate();
                $currentActiveCount = $activeSessionsQuery->count();

                // Check if THIS guard already has an active session
                $existingGuardSession = SecurityDutySession::where('user_id', $guard->id)
                    ->where('status', SecurityDutySession::STATUS_ACTIVE)
                    ->first();

                if (!$existingGuardSession && $currentActiveCount >= self::MAX_ACTIVE_SESSIONS) {
                    throw new InvalidArgumentException('Maximum active Security sessions (4) reached. Another guard must end duty first.');
                }

                // If guard already had an active session, gracefully end it first
                if ($existingGuardSession) {
                    $existingGuardSession->end();
                }

                $dutySession = SecurityDutySession::create([
                    'user_id' => $guard->id,
                    'gate_id' => $gate->id,
                    'started_at' => now('Asia/Kolkata'),
                    'status' => SecurityDutySession::STATUS_ACTIVE,
                    'device_identifier' => $deviceIdentifier,
                    'ip_address' => $ipAddress,
                ]);

                $this->auditService->log(
                    action: 'DUTY_ACTIVATED',
                    module: 'DUTY',
                    status: AuditLog::STATUS_SUCCESS,
                    metadata: [
                        'session_id' => $dutySession->id,
                        'gate_id' => $gate->id,
                        'gate_name' => $gate->name,
                        'otp_id' => $otpRecord->id,
                    ],
                    entityType: SecurityDutySession::class,
                    entityId: (string) $dutySession->id,
                    user: $guard
                );

                return $dutySession;
            });
        } catch (InvalidArgumentException $e) {
            $this->auditService->log(
                action: 'DUTY_OTP_VERIFICATION_FAILED',
                module: 'DUTY',
                status: AuditLog::STATUS_FAILED,
                metadata: [
                    'security_user_id' => $guard->id,
                    'gate_id' => $gate->id,
                    'error' => $e->getMessage(),
                ],
                user: $guard
            );

            throw $e;
        }
    }

    /**
     * Get active duty session for a guard, auto-expiring sessions past shift limits.
     */
    public function getActiveSession(int $userId): ?SecurityDutySession
    {
        $session = SecurityDutySession::with('gate')
            ->where('user_id', $userId)
            ->where('status', SecurityDutySession::STATUS_ACTIVE)
            ->first();

        if (!$session) {
            return null;
        }

        // Auto-expire sessions older than 12 hours
        if ($session->started_at->diffInHours(now('Asia/Kolkata')) >= self::MAX_SHIFT_HOURS) {
            $session->update([
                'status' => SecurityDutySession::STATUS_ENDED,
                'ended_at' => now('Asia/Kolkata'),
            ]);
            return null;
        }

        return $session;
    }

    /**
     * End active duty session and release slot.
     */
    public function endDuty(SecurityDutySession $session, ?User $actor = null): void
    {
        $session->end();

        $this->auditService->log(
            action: 'DUTY_ENDED',
            module: 'DUTY',
            status: AuditLog::STATUS_SUCCESS,
            metadata: [
                'session_id' => $session->id,
                'gate_id' => $session->gate_id,
                'duration_minutes' => $session->started_at->diffInMinutes($session->ended_at),
            ],
            entityType: SecurityDutySession::class,
            entityId: (string) $session->id,
            user: $actor ?? $session->user
        );
    }

    /**
     * Generate an Admin-authorized OTP for duty activation.
     */
    public function generateAdminOtp(User $admin, ?int $gateId = null, ?int $securityUserId = null, int $ttlMinutes = 15): array
    {
        $plainOtp = str_pad((string) random_int(100000, 999999), 6, '0', STR_PAD_LEFT);

        $otpRecord = AdminDutyOtp::create([
            'otp_hash' => Hash::make($plainOtp),
            'admin_user_id' => $admin->id,
            'security_user_id' => $securityUserId,
            'gate_id' => $gateId,
            'expires_at' => now('Asia/Kolkata')->addMinutes($ttlMinutes),
        ]);

        $this->auditService->log(
            action: 'ADMIN_OTP_GENERATED',
            module: 'DUTY',
            status: AuditLog::STATUS_SUCCESS,
            metadata: [
                'otp_id' => $otpRecord->id,
                'expires_at' => $otpRecord->expires_at->toIso8601String(),
            ],
            entityType: AdminDutyOtp::class,
            entityId: (string) $otpRecord->id,
            user: $admin
        );

        return [
            'otp' => $plainOtp,
            'expires_at' => $otpRecord->expires_at->toIso8601String(),
            'ttl_minutes' => $ttlMinutes,
        ];
    }

    protected function verifyAndBurnOtp(string $otp, int $securityUserId, int $gateId): AdminDutyOtp
    {
        $validOtps = AdminDutyOtp::whereNull('used_at')
            ->where('expires_at', '>', now('Asia/Kolkata'))
            ->get();

        $matchedOtp = null;
        foreach ($validOtps as $candidate) {
            if (Hash::check($otp, $candidate->otp_hash)) {
                if ($candidate->gate_id && $candidate->gate_id !== $gateId) {
                    continue;
                }
                if ($candidate->security_user_id && $candidate->security_user_id !== $securityUserId) {
                    continue;
                }
                $matchedOtp = $candidate;
                break;
            }
        }

        if (!$matchedOtp) {
            throw new InvalidArgumentException('Invalid or expired Admin authorization OTP.');
        }

        // Lock row to prevent race condition reuse
        $lockedOtp = AdminDutyOtp::where('id', $matchedOtp->id)->lockForUpdate()->first();
        if ($lockedOtp->used_at !== null) {
            throw new InvalidArgumentException('This Admin authorization OTP has already been used.');
        }

        $lockedOtp->update(['used_at' => now('Asia/Kolkata')]);

        return $lockedOtp;
    }
}
