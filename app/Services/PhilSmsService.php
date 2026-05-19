<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PhilSmsService
{
    private string $token;
    private string $senderId;
    private string $baseUrl = 'https://dashboard.philsms.com/api/v3';
    private bool $enabled;

    /** Maximum safe SMS length. Messages beyond this are truncated with a suffix. */
    public const MAX_LENGTH = 160;

    public function __construct()
    {
        $this->token    = config('services.philsms.token', '');
        $this->senderId = config('services.philsms.sender_id', 'PhilSMS');
        $this->enabled  = (bool) config('services.philsms.enabled', false);
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
                Log::info('PhilSMS: SMS sent successfully', [
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
     * Logs aggregate results rather than per-recipient to avoid log flooding.
     *
     * @param  array<string>  $phones   List of raw phone numbers
     * @param  string         $message
     * @return array{sent: int, failed: int}
     */
    public function sendBulk(array $phones, string $message): array
    {
        $sent   = 0;
        $failed = 0;

        foreach ($phones as $phone) {
            $this->send($phone, $message) ? $sent++ : $failed++;
        }

        Log::info('PhilSMS: bulk send completed', [
            'sent'   => $sent,
            'failed' => $failed,
        ]);

        return compact('sent', 'failed');
    }

    /**
     * Normalise Philippine phone numbers to E.164 (+639XXXXXXXXX).
     * Returns null for unrecognised formats so the caller can skip invalid numbers.
     */
    private function normalizePhone(string $phone): ?string
    {
        $digits = preg_replace('/\D/', '', $phone);

        if (strlen($digits) === 11 && str_starts_with($digits, '09')) {
            return '+63' . substr($digits, 1);
        }
        if (strlen($digits) === 12 && str_starts_with($digits, '639')) {
            return '+' . $digits;
        }
        if (strlen($digits) === 13 && str_starts_with($digits, '6309')) {
            // Edge case: user typed 6309... instead of 639...
            return '+63' . substr($digits, 3);
        }
        if (strlen($digits) === 10 && str_starts_with($digits, '9')) {
            return '+63' . $digits;
        }

        return null;
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