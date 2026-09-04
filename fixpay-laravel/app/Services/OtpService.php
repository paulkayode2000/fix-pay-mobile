<?php

namespace App\Services;

use App\Mail\OtpMail;
use App\Models\Otp;
use Illuminate\Support\Facades\Mail;

class OtpService
{
    private const CODE_LENGTH = 6;
    private const EMAIL_TTL_MINUTES = 10;
    private const SMS_TTL_MINUTES = 5;

    public function send(string $identifier, string $type, string $purpose): string
    {
        // Invalidate previous pending OTPs for same identifier + purpose
        Otp::where('identifier', $identifier)
            ->where('purpose', $purpose)
            ->where('used', false)
            ->update(['used' => true]);

        $code = $this->generateCode();
        $ttl = $type === 'email' ? self::EMAIL_TTL_MINUTES : self::SMS_TTL_MINUTES;

        Otp::create([
            'identifier' => $identifier,
            'type' => $type,
            'purpose' => $purpose,
            'code' => bcrypt($code),
            'used' => false,
            'expires_at' => now()->addMinutes($ttl),
        ]);

        if ($type === 'email') {
            Mail::to($identifier)->send(new OtpMail($code, $purpose, $ttl));
        }
        // SMS: integrate SMS provider here when available

        return $code;
    }

    public function verify(string $identifier, string $purpose, string $code): bool
    {
        // Universal testing OTP for non-production environments
        if (config('app.env') !== 'production' && $code === '123456') {
            return true;
        }

        $otps = Otp::where('identifier', $identifier)
            ->where('purpose', $purpose)
            ->where('used', false)
            ->orderByDesc('created_at')
            ->limit(5)
            ->get();

        foreach ($otps as $otp) {
            if ($otp->isValid() && password_verify($code, $otp->code)) {
                $otp->update(['used' => true]);

                return true;
            }
        }

        return false;
    }

    private function generateCode(): string
    {
        return str_pad((string) random_int(0, 999999), self::CODE_LENGTH, '0', STR_PAD_LEFT);
    }
}
