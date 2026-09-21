<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\AuditLog;
use App\Models\Student;
use App\Models\User;
use App\Services\AuditService;
use App\Services\ProfilePhotoService;
use App\Services\TotpService;
use Laravel\Sanctum\PersonalAccessToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected AuditService $auditService,
        protected TotpService $totpService,
        protected ProfilePhotoService $profilePhotoService
    ) {}

    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $ip = $request->ip() ?? '127.0.0.1';
        $throttleKey = 'login:' . Str::lower($validated['email']) . '|' . $ip;

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);
            return $this->error("Too many login attempts. Please try again in {$seconds} seconds.", null, 429);
        }

        $user = User::where('email', $validated['email'])->first();

        if (!$user || !Hash::check($validated['password'], $user->password)) {
            RateLimiter::hit($throttleKey, 300);

            $this->auditService->log(
                action: 'FAILED_LOGIN',
                module: 'AUTH',
                status: AuditLog::STATUS_FAILED,
                metadata: ['email' => $validated['email']],
                user: $user
            );

            return $this->error('Invalid email address or password.', null, 401);
        }

        if ($user->isSuspended()) {
            return $this->forbidden('Your account has been suspended by administration. Please contact administration.');
        }

        if ($user->isRejected()) {
            return $this->forbidden('Your student registration was rejected by administration.');
        }

        if (!$user->isActive() && !$user->isPending()) {
            return $this->forbidden('Your account is currently inactive. Please contact administration.');
        }

        RateLimiter::clear($throttleKey);

        // Check if 2FA is enabled
        if ($user->totp_enabled) {
            $challengeToken = Str::random(64);
            Cache::put("2fa_challenge_{$challengeToken}", $user->id, now()->addMinutes(5));

            $this->auditService->log(
                action: '2FA_CHALLENGE_REQUIRED',
                module: 'AUTH',
                status: AuditLog::STATUS_SUCCESS,
                user: $user
            );

            return $this->success([
                'requires_2fa' => true,
                'challenge_token' => $challengeToken,
                'user' => [
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role,
                ],
            ], 'Two-factor authentication code required.');
        }

        // Direct login
        $now = now('Asia/Kolkata');
        $expiresAt = null;
        if ($user->isStudent() || $user->isSecurity()) {
            $expiresAt = $now->copy()->addHours(8);
        }

        $token = $user->createToken('smartgate_auth', ['*'], $expiresAt)->plainTextToken;

        $this->auditService->log(
            action: 'LOGIN',
            module: 'AUTH',
            status: AuditLog::STATUS_SUCCESS,
            user: $user
        );

        return $this->success([
            'requires_2fa' => false,
            'token' => $token,
            'user' => $this->formatUserData($user),
            'session_started_at' => $now->toIso8601String(),
            'session_expires_at' => $expiresAt?->toIso8601String(),
            'session_duration_seconds' => $expiresAt ? 8 * 3600 : null,
        ], 'Login successful.');
    }

    public function verify2fa(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'challenge_token' => ['required', 'string'],
            'code' => ['nullable', 'string'],
            'recovery_code' => ['nullable', 'string'],
        ]);

        $userId = Cache::get("2fa_challenge_{$validated['challenge_token']}");

        if (!$userId) {
            return $this->error('The two-factor authentication challenge has expired. Please log in again.', null, 401);
        }

        $user = User::find($userId);
        if (!$user || !$user->isActive()) {
            return $this->error('Invalid user session.', null, 401);
        }

        $throttleKey = '2fa_verify:' . $user->id;
        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);
            return $this->error("Too many 2FA verification attempts. Try again in {$seconds} seconds.", null, 429);
        }

        $isValid = false;
        $isRecovery = false;

        if (!empty($validated['code'])) {
            $isValid = $this->totpService->verifyKey($user->totp_secret, $validated['code']);
        } elseif (!empty($validated['recovery_code'])) {
            $isValid = $this->totpService->verifyAndBurnRecoveryCode($user, $validated['recovery_code']);
            $isRecovery = true;
        }

        if (!$isValid) {
            RateLimiter::hit($throttleKey, 300);

            $this->auditService->log(
                action: '2FA_VERIFICATION_FAILED',
                module: 'AUTH',
                status: AuditLog::STATUS_FAILED,
                metadata: ['is_recovery' => $isRecovery],
                user: $user
            );

            return $this->error('Invalid verification code or recovery code.', null, 422);
        }

        RateLimiter::clear($throttleKey);
        Cache::forget("2fa_challenge_{$validated['challenge_token']}");

        $now = now('Asia/Kolkata');
        $expiresAt = null;
        if ($user->isStudent() || $user->isSecurity()) {
            $expiresAt = $now->copy()->addHours(8);
        }

        $token = $user->createToken('smartgate_auth', ['*'], $expiresAt)->plainTextToken;

        $this->auditService->log(
            action: $isRecovery ? 'LOGIN_WITH_RECOVERY_CODE' : 'LOGIN_2FA_SUCCESS',
            module: 'AUTH',
            status: AuditLog::STATUS_SUCCESS,
            user: $user
        );

        return $this->success([
            'requires_2fa' => false,
            'token' => $token,
            'user' => $this->formatUserData($user),
            'session_started_at' => $now->toIso8601String(),
            'session_expires_at' => $expiresAt?->toIso8601String(),
            'session_duration_seconds' => $expiresAt ? 8 * 3600 : null,
        ], 'Two-factor authentication verified successfully.');
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user) {
            $this->auditService->log(
                action: 'LOGOUT',
                module: 'AUTH',
                status: AuditLog::STATUS_SUCCESS,
                user: $user
            );

            if ($user->isSecurity()) {
                $activeSession = \App\Models\SecurityDutySession::where('user_id', $user->id)
                    ->where('status', \App\Models\SecurityDutySession::STATUS_ACTIVE)
                    ->first();
                if ($activeSession) {
                    $activeSession->end();
                }
            }

            $token = $user->currentAccessToken();
            if ($token && method_exists($token, 'delete')) {
                $token->delete();
            }
        }

        return $this->success(null, 'Logged out successfully.');
    }

    public function refresh(Request $request): JsonResponse
    {
        $tokenStr = $request->bearerToken();
        if (!$tokenStr) {
            return $this->error('No token provided.', null, 401);
        }

        $accessToken = \Laravel\Sanctum\PersonalAccessToken::findToken($tokenStr);
        if (!$accessToken) {
            return $this->error('Invalid or fully revoked token.', null, 401);
        }

        $user = $accessToken->tokenable;
        if (!$user || !$user->isActive()) {
            return $this->error('User account is inactive or suspended.', null, 401);
        }

        // Authoritative 8-hour check: reject expired tokens
        if ($accessToken->expires_at && $accessToken->expires_at->isPast()) {
            $accessToken->delete();
            return $this->error('Your 8-hour session has expired. Please sign in again.', null, 401);
        }

        if (($user->isStudent() || $user->isSecurity()) && $accessToken->created_at && $accessToken->created_at->addHours(8)->isPast()) {
            $accessToken->delete();
            return $this->error('Your 8-hour session has expired. Please sign in again.', null, 401);
        }

        // Preserve original expires_at: never extend 8-hour session
        $expiresAt = $accessToken->expires_at;

        // Revoke the old token
        $accessToken->delete();

        // Issue a new one with same original expiry
        $newToken = $user->createToken('smartgate_auth', ['*'], $expiresAt)->plainTextToken;

        return $this->success([
            'token' => $newToken,
            'user' => $this->formatUserData($user),
            'session_expires_at' => $expiresAt?->toIso8601String(),
        ], 'Token refreshed successfully.');
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        $token = $user->currentAccessToken();

        return $this->success([
            'user' => $this->formatUserData($user),
            'session_started_at' => $token?->created_at?->toIso8601String(),
            'session_expires_at' => $token?->expires_at?->toIso8601String(),
            'server_time' => now('Asia/Kolkata')->toIso8601String(),
        ], 'User profile loaded.');
    }

    public function setup2fa(Request $request): JsonResponse
    {
        $user = $request->user();
        $secret = $this->totpService->generateSecret();
        $otpauthUrl = $this->totpService->getOtpAuthUri($user->email, $secret);

        // Store secret temporarily in cache for 10 minutes until verified
        Cache::put("2fa_setup_{$user->id}", $secret, now()->addMinutes(10));

        return $this->success([
            'secret' => $secret,
            'otpauth_url' => $otpauthUrl,
        ], 'Scan this QR code with Microsoft Authenticator or any TOTP app.');
    }

    public function enable2fa(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string'],
        ]);

        $user = $request->user();
        $secret = Cache::get("2fa_setup_{$user->id}");

        if (!$secret) {
            return $this->error('2FA setup session expired. Please restart the setup.', null, 422);
        }

        if (!$this->totpService->verifyKey($secret, $validated['code'])) {
            return $this->error('Invalid verification code. Please check your authenticator app and try again.', null, 422);
        }

        $recoveryCodes = $this->totpService->generateRecoveryCodes(8);

        $user->update([
            'totp_secret' => $secret,
            'totp_enabled' => true,
            'totp_recovery_codes' => $recoveryCodes,
        ]);

        Cache::forget("2fa_setup_{$user->id}");

        $this->auditService->log(
            action: '2FA_ENABLED',
            module: 'AUTH',
            status: AuditLog::STATUS_SUCCESS,
            user: $user
        );

        return $this->success([
            'recovery_codes' => $recoveryCodes,
        ], 'Two-factor authentication successfully enabled. Store these recovery codes securely.');
    }

    public function disable2fa(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'password' => ['required', 'string'],
            'code' => ['required', 'string'],
        ]);

        $user = $request->user();

        if (!Hash::check($validated['password'], $user->password)) {
            return $this->error('Password verification failed.', null, 422);
        }

        if (!$this->totpService->verifyKey($user->totp_secret, $validated['code'])) {
            return $this->error('Invalid authenticator code.', null, 422);
        }

        $user->update([
            'totp_secret' => null,
            'totp_enabled' => false,
            'totp_recovery_codes' => null,
        ]);

        $this->auditService->log(
            action: '2FA_DISABLED',
            module: 'AUTH',
            status: AuditLog::STATUS_SUCCESS,
            user: $user
        );

        return $this->success(null, 'Two-factor authentication has been disabled.');
    }

    protected function formatUserData(User $user): array
    {
        $data = [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'status' => $user->status,
            'totp_enabled' => (bool) $user->totp_enabled,
        ];

        if ($user->isStudent()) {
            $student = $user->student;
            $data['student'] = $student ? [
                'id' => $student->id,
                'student_id' => $student->student_id,
                'roll_number' => $student->roll_number,
                'name' => $student->name,
                'year' => $student->year,
                'program' => $student->program,
                'department' => $student->department,
                'semester' => $student->semester,
                'batch' => $student->batch,
                'student_type' => $student->student_type,
                'category' => $student->category,
                'profile_photo' => $student->profile_photo,
                'profile_photo_url' => $student->profile_photo_url,
                'current_status' => $student->current_status,
                'last_movement_at' => $student->last_movement_at?->toIso8601String(),
            ] : null;
        }

        return $data;
    }

    /**
     * Self-registration for University Students.
     * Newly registered accounts default to PENDING status and cannot access gate entry until approved.
     */
    public function registerStudent(Request $request): JsonResponse
    {
        // Allow fallback from category if student_type not explicitly sent
        if (!$request->has('student_type') && $request->has('category')) {
            $cat = strtoupper(trim((string) $request->input('category')));
            if ($cat === 'HOSTELER' || $cat === 'HOSTELLER') {
                $request->merge(['student_type' => 'HOSTELLER']);
            } elseif ($cat === 'DAY_SCHOLAR') {
                $request->merge(['student_type' => 'DAY_SCHOLAR']);
            }
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'roll_number' => ['required', 'string', 'max:50', 'unique:students,roll_number'],
            'student_id' => ['nullable', 'string', 'max:50', 'unique:students,student_id'],
            'student_type' => ['required', 'string', 'in:HOSTELLER,DAY_SCHOLAR'],
            'year' => ['required', 'integer', 'between:1,6'],
            'program' => ['required', 'string', 'max:100'],
            'department' => ['nullable', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:150', 'unique:users,email', 'unique:students,email'],
            'phone_number' => ['required', 'string', 'max:25', 'unique:students,phone_number'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ], [
            'student_type.required' => 'Please select Hosteller or Day Scholar.',
            'student_type.in' => 'Student Type must be either HOSTELLER or DAY_SCHOLAR.',
            'roll_number.unique' => 'A student account with this Roll Number is already registered.',
            'student_id.unique' => 'A student account with this Student ID is already registered.',
            'email.unique' => 'A user account with this email address already exists.',
            'phone_number.unique' => 'A student account with this phone number is already registered.',
        ]);

        $photoFile = $request->file('profile_photo');
        if ($photoFile) {
            $this->profilePhotoService->validateImage($photoFile);
        }

        $studentId = !empty($validated['student_id']) ? trim($validated['student_id']) : trim($validated['roll_number']);
        $storedPhotoPath = null;

        try {
            $result = DB::transaction(function () use ($validated, $studentId, $photoFile, &$storedPhotoPath) {
                $user = User::create([
                    'name' => trim($validated['name']),
                    'email' => strtolower(trim($validated['email'])),
                    'password' => Hash::make($validated['password']),
                    'role' => User::ROLE_STUDENT,
                    'status' => User::STATUS_PENDING,
                ]);

                $student = Student::create([
                    'user_id' => $user->id,
                    'student_id' => $studentId,
                    'roll_number' => trim($validated['roll_number']),
                    'name' => trim($validated['name']),
                    'year' => (int) $validated['year'],
                    'email' => strtolower(trim($validated['email'])),
                    'phone_number' => trim($validated['phone_number']),
                    'program' => trim($validated['program']),
                    'department' => !empty($validated['department']) ? trim($validated['department']) : null,
                    'student_type' => $validated['student_type'],
                    'category' => ($validated['student_type'] === 'DAY_SCHOLAR') ? Student::CATEGORY_DAY_SCHOLAR : Student::CATEGORY_HOSTELER,
                    'status' => Student::STATUS_PENDING,
                    'current_status' => Student::STATE_INSIDE,
                ]);

                if ($photoFile) {
                    $storedPhotoPath = $this->profilePhotoService->processAndStore(
                        file: $photoFile,
                        student: $student,
                        actor: $user
                    );
                }

                $this->auditService->log(
                    action: 'STUDENT_REGISTERED',
                    module: 'AUTH',
                    status: AuditLog::STATUS_SUCCESS,
                    metadata: [
                        'student_id' => $student->id,
                        'roll_number' => $student->roll_number,
                        'email' => $student->email,
                        'student_type' => $student->student_type,
                        'has_profile_photo' => (bool) $student->profile_photo,
                    ],
                    entityType: Student::class,
                    entityId: (string) $student->id,
                    user: $user
                );

                return [$student, $user];
            });
        } catch (\Throwable $e) {
            if ($storedPhotoPath && Storage::disk('local')->exists($storedPhotoPath)) {
                Storage::disk('local')->delete($storedPhotoPath);
            }
            throw $e;
        }

        [$student, $user] = $result;

        try {
            event(new \App\Events\StudentRegistered($student, $user));
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('StudentRegistered event dispatch failure: ' . $e->getMessage());
        }

        return $this->created([
            'student' => [
                'id' => $student->id,
                'roll_number' => $student->roll_number,
                'student_id' => $student->student_id,
                'name' => $student->name,
                'email' => $student->email,
                'student_type' => $student->student_type,
                'category' => $student->category,
                'profile_photo' => $student->profile_photo,
                'profile_photo_url' => $student->profile_photo_url,
                'status' => $student->status,
            ],
        ], 'Registration submitted successfully. Your account is pending verification and approval by University Administration.');
    }
}
