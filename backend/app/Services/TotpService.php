<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

class TotpService
{
    protected Google2FA $google2fa;

    public function __construct()
    {
        $this->google2fa = new Google2FA();
        $this->google2fa->setWindow(1); // 1 period drift allowance for network/clock skew
    }

    public function generateSecret(): string
    {
        return $this->google2fa->generateSecretKey(32);
    }

    public function getOtpAuthUri(string $email, string $secret): string
    {
        return $this->google2fa->getQRCodeUrl(
            config('app.name', 'SmartGate'),
            $email,
            $secret
        );
    }

    public function verifyKey(string $secret, string $code): bool
    {
        // Clean code of spaces or dashes
        $cleanCode = str_replace([' ', '-'], '', $code);
        if (!preg_match('/^\d{6}$/', $cleanCode)) {
            return false;
        }

        return (bool) $this->google2fa->verifyKey($secret, $cleanCode, 1);
    }

    public function generateRecoveryCodes(int $count = 8): array
    {
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $codes[] = strtoupper(Str::random(5) . '-' . Str::random(5));
        }
        return $codes;
    }

    public function verifyAndBurnRecoveryCode(User $user, string $providedCode): bool
    {
        $cleanProvided = strtoupper(trim($providedCode));
        $codes = $user->totp_recovery_codes ?? [];

        foreach ($codes as $index => $code) {
            if (hash_equals($code, $cleanProvided)) {
                // Burn the code
                unset($codes[$index]);
                $user->totp_recovery_codes = array_values($codes);
                $user->save();
                return true;
            }
        }

        return false;
    }
}
