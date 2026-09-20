<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\AuditLog;
use App\Models\Gate;
use App\Services\AuditService;
use App\Services\DutySessionService;
use App\Services\QrTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GateController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected QrTokenService $qrTokenService,
        protected AuditService $auditService,
        protected DutySessionService $dutySessionService
    ) {}

    public function index(): JsonResponse
    {
        $gates = Gate::where('status', Gate::STATUS_ACTIVE)->get();

        return $this->success($gates, 'Active gates retrieved.');
    }

    public function show(Gate $gate): JsonResponse
    {
        return $this->success($gate, 'Gate details retrieved.');
    }

    /**
     * Generate a new temporary 30-second QR token for a gate.
     * Accessible by Security guards on active duty at this gate, or Admin.
     */
    public function generateQr(Request $request, Gate $gate): JsonResponse
    {
        $user = $request->user();

        if (!$gate->isActive()) {
            return $this->error('This gate is currently inactive.', null, 422);
        }

        // If security officer, verify active duty session at THIS gate
        if ($user->isSecurity()) {
            if (!$user->isActive()) {
                return $this->forbidden('Your security officer account is currently inactive or suspended.');
            }

            $activeSession = $this->dutySessionService->getActiveSession($user->id);

            if (!$activeSession || $activeSession->user_id !== $user->id || !$activeSession->isActive() || $activeSession->gate_id !== $gate->id) {
                return $this->forbidden('Operational access denied: You must have an active Admin-authorized duty session assigned to this gate to generate its QR code.');
            }
        } elseif (!$user->isAdmin()) {
            return $this->forbidden('Unauthorized to generate Gate QR.');
        }

        $tokenData = $this->qrTokenService->generateToken($gate, 30);

        $this->auditService->log(
            action: 'QR_GENERATED',
            module: 'GATE',
            status: AuditLog::STATUS_SUCCESS,
            metadata: [
                'gate_id' => $gate->id,
                'gate_code' => $gate->code,
                'token_id' => $tokenData['token_id'],
                'expires_at' => $tokenData['expires_at'],
            ],
            entityType: Gate::class,
            entityId: (string) $gate->id,
            user: $user
        );

        return $this->success($tokenData, 'Temporary Gate QR generated successfully.');
    }
}
