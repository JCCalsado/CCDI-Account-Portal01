<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PhilSmsService
{
    private string $baseUrl;
    private string $token;
    private string $senderId;
    private bool   $enabled;

    public const MAX_LENGTH = 160;

    public function __construct()
    {
        $this->baseUrl   = rtrim(config('services.philsms.base_url', 'https://dashboard.philsms.com/api/v3'), '/');
        $this->token     = config('services.philsms.token', '');
        $this->senderId  = config('services.philsms.sender_id', 'PhilSMS');
        $this->enabled   = (bool) config('services.philsms.enabled', false);
    }

    /**
     * Send a single SMS via PhilSMS API.
     *
     * @param  string  $phone   Raw phone number (09XX, +639XX, 639XX accepted)
     * @param  string  $message Plain-text message content
     * @return bool             True on confirmed delivery, false on any failure
     */
    public function send(string $phone, string $message): bool
    {
        if (! $this->enabled || empty($this->token)) {
            Log::info('PhilSMS: disabled or no token — SMS skipped', ['phone' => $phone]);
            return false;
        }

        $normalized = $this->normalizePhone($phone);

        if (! $normalized) {
            Log::warning('PhilSMS: invalid phone number skipped', ['raw' => $phone]);
            return false;
        }

        $message = $this->truncate($message);

        try {
            $response = Http::withToken($this->token)
                ->timeout(15)
                ->post($this->baseUrl . '/sms/send', [
                    'sender_id' => $this->senderId,
                    'message'   => $message,
                    'recipient' => $normalized,
                ]);

            $body = $response->json();

            if ($response->successful() && ($body['status'] ?? '') === 'success') {
                Log::info('PhilSMS: SMS sent', [
                    'phone'   => $normalized,
                    'preview' => substr($message, 0, 40),
                ]);
                return true;
            }

            Log::error('PhilSMS: send failed', [
                'phone'    => $normalized,
                'status'   => $response->status(),
                'response' => $body,
            ]);
            return false;

        } catch (\Throwable $e) {
            Log::error('PhilSMS: exception during send', [
                'phone' => $normalized,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Send SMS to multiple recipients.
     *
     * @param  array<string>  $phones
     * @return array{sent: int, failed: int}
     */
    public function sendBulk(array $phones, string $message): array
    {
        $sent   = 0;
        $failed = 0;

        foreach ($phones as $phone) {
            $this->send($phone, $message) ? $sent++ : $failed++;
        }

        Log::info('PhilSMS: bulk send completed', compact('sent', 'failed'));

        return compact('sent', 'failed');
    }

    /**
     * Normalise Philippine phone numbers to E.164 (+639XXXXXXXXX).
     * Returns null for unrecognised formats.
     */
    private function normalizePhone(string $phone): ?string
    {
        $digits = preg_replace('/\D/', '', $phone);

        return match (true) {
            // 09XXXXXXXXX → +639XXXXXXXXX
            strlen($digits) === 11 && str_starts_with($digits, '09')
                => '+63' . substr($digits, 1),

            // 639XXXXXXXXX → +639XXXXXXXXX
            strlen($digits) === 12 && str_starts_with($digits, '639')
                => '+' . $digits,

            // 9XXXXXXXXX → +639XXXXXXXXX
            strlen($digits) === 10 && str_starts_with($digits, '9')
                => '+63' . $digits,

            default => null,
        };
    }

    /**
     * Truncate message to MAX_LENGTH, appending an ellipsis if cut.
     */
    private function truncate(string $message): string
    {
        if (mb_strlen($message) <= self::MAX_LENGTH) {
            return $message;
        }

        return mb_substr($message, 0, self::MAX_LENGTH - 3) . '...';
    }
}