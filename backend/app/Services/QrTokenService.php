<?php

namespace App\Services;

use App\Models\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;

class QrTokenService
{
    protected string $secretKey;

    public function __construct()
    {
        $this->secretKey = config('app.key') ?: 'smartgate_fallback_secret_key_32bytes!';
    }

    /**
     * Generate a cryptographically signed temporary Gate QR token.
     * Default lifetime: 30 seconds.
     */
    public function generateToken(Gate $gate, int $lifetimeSeconds = 30): array
    {
        $issuedAt = now('Asia/Kolkata')->timestamp;
        $expiresAt = $issuedAt + $lifetimeSeconds;
        $tokenId = Str::uuid()->toString();

        $data = [
            'gate_id' => $gate->id,
            'token_id' => $tokenId,
            'issued_at' => $issuedAt,
            'expires_at' => $expiresAt,
        ];

        $signature = $this->calculateSignature($data);

        $payload = array_merge($data, [
            'gate_name' => $gate->name,
            'gate_code' => $gate->code,
            'signature' => $signature,
        ]);

        $payloadString = json_encode($payload);

        return [
            'gate_id' => $gate->id,
            'gate_name' => $gate->name,
            'gate_code' => $gate->code,
            'token_id' => $tokenId,
            'issued_at' => $issuedAt,
            'expires_at' => $expiresAt,
            'lifetime_seconds' => $lifetimeSeconds,
            'signature' => $signature,
            'qr_payload' => $payloadString,
        ];
    }

    /**
     * Verify a temporary QR token scanned by a student.
     * Throws InvalidArgumentException with user-friendly messages on failure.
     */
    public function verifyToken(mixed $rawPayload): array
    {
        if (is_string($rawPayload)) {
            $data = json_decode($rawPayload, true);
            if (!is_array($data)) {
                throw new InvalidArgumentException('Invalid QR code format.');
            }
        } elseif (is_array($rawPayload)) {
            $data = $rawPayload;
        } else {
            throw new InvalidArgumentException('Invalid QR payload.');
        }

        // Check required fields
        foreach (['gate_id', 'token_id', 'issued_at', 'expires_at', 'signature'] as $field) {
            if (!isset($data[$field])) {
                throw new InvalidArgumentException('Invalid Gate QR. Missing verification elements.');
            }
        }

        // Verify cryptographic signature to prevent tampering
        $expectedSignature = $this->calculateSignature([
            'gate_id' => (int) $data['gate_id'],
            'token_id' => (string) $data['token_id'],
            'issued_at' => (int) $data['issued_at'],
            'expires_at' => (int) $data['expires_at'],
        ]);

        if (!hash_equals($expectedSignature, $data['signature'])) {
            throw new InvalidArgumentException('Invalid or tampered Gate QR code.');
        }

        // Expiry check (with 5-second leeway for device clock variance)
        $currentTime = now('Asia/Kolkata')->timestamp;
        if ($currentTime > ((int) $data['expires_at'] + 5)) {
            throw new InvalidArgumentException('QR expired. Please scan the current Gate QR.');
        }

        // Verify Gate exists and is currently active
        $gate = Gate::find($data['gate_id']);
        if (!$gate || !$gate->isActive()) {
            throw new InvalidArgumentException('The gate associated with this QR is currently inactive or invalid.');
        }

        // Verify that the gate currently has an active security duty session
        $hasActiveDuty = \App\Models\SecurityDutySession::where('gate_id', $gate->id)
            ->where('status', \App\Models\SecurityDutySession::STATUS_ACTIVE)
            ->whereNull('ended_at')
            ->exists();

        if (!$hasActiveDuty) {
            throw new InvalidArgumentException('Gate verification unavailable: No authorized security officer is currently on active duty at this gate.');
        }

        return [
            'gate' => $gate,
            'token_id' => $data['token_id'],
            'issued_at' => $data['issued_at'],
            'expires_at' => $data['expires_at'],
        ];
    }

    protected function calculateSignature(array $data): string
    {
        $payloadToSign = implode('|', [
            $data['gate_id'],
            $data['token_id'],
            $data['issued_at'],
            $data['expires_at'],
        ]);

        return hash_hmac('sha256', $payloadToSign, $this->secretKey);
    }
}
