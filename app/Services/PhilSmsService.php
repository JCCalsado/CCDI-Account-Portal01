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

    /** Maximum safe single-SMS length for GSM-7 encoding. */
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
     * @param  string  $phone   Raw phone number — 09XX / +639XX / 639XX / 9XX all accepted
     * @param  string  $message Plain-text content (truncated to MAX_LENGTH if needed)
     * @return bool             True when PhilSMS confirms delivery, false on any failure
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

            // Log the FULL raw API response body — no more flying blind.
            $body = $response->json();

            Log::info('PhilSMS: raw API response', [
                'phone'         => $normalized,
                'http_status'   => $response->status(),
                'response_body' => $body,
                'message_chars' => mb_strlen($message),
            ]);

            // PhilSMS returns {"status":"success"} on accepted delivery.
            // The nested data.status field ("Delivered", "Pending", etc.)
            // is informational — we trust the top-level status for now.
            if ($response->successful() && ($body['status'] ?? '') === 'success') {
                Log::info('PhilSMS: SMS accepted', [
                    'phone'       => $normalized,
                    'uid'         => $body['data']['uid']    ?? null,
                    'sms_status'  => $body['data']['status'] ?? null,
                    'cost'        => $body['data']['cost']   ?? null,
                    'sender_used' => $body['data']['from']   ?? null,
                    'preview'     => mb_substr($message, 0, 50),
                ]);
                return true;
            }

            Log::error('PhilSMS: send rejected by API', [
                'phone'    => $normalized,
                'status'   => $response->status(),
                'response' => $body,
                'preview'  => mb_substr($message, 0, 50),
            ]);
            return false;

        } catch (\Throwable $e) {
            Log::error('PhilSMS: HTTP exception during send', [
                'phone' => $normalized,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Send SMS to multiple recipients.
     * Returns aggregate counts for logging by the caller.
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
     * Normalise Philippine mobile numbers to E.164 format (+639XXXXXXXXX).
     * Returns null when the format is unrecognised so the caller can skip it.
     */
    private function normalizePhone(string $phone): ?string
    {
        $digits = preg_replace('/\D/', '', $phone);

        return match (true) {
            // 09XXXXXXXXX  (11 digits, starts with 09)
            strlen($digits) === 11 && str_starts_with($digits, '09')
                => '+63' . substr($digits, 1),

            // 639XXXXXXXXX (12 digits, starts with 639)
            strlen($digits) === 12 && str_starts_with($digits, '639')
                => '+' . $digits,

            // 9XXXXXXXXX   (10 digits, starts with 9)
            strlen($digits) === 10 && str_starts_with($digits, '9')
                => '+63' . $digits,

            default => null,
        };
    }

    /**
     * Truncate message to MAX_LENGTH characters, appending ellipsis if cut.
     */
    private function truncate(string $message): string
    {
        if (mb_strlen($message) <= self::MAX_LENGTH) {
            return $message;
        }

        return mb_substr($message, 0, self::MAX_LENGTH - 3) . '...';
    }
}