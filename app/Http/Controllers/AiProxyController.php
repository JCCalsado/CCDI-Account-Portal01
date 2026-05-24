<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * AiProxyController
 *
 * Proxies Anthropic API calls from the browser through Laravel.
 *
 * WHY THIS EXISTS
 * ───────────────
 * Browsers block direct calls to api.anthropic.com because Anthropic does not
 * allow arbitrary origins (CORS). The API key must also never be exposed in
 * frontend JavaScript — it would be visible to any user who opens DevTools.
 *
 * This controller acts as a thin, rate-limited relay:
 *   Browser → POST /ai/verify → Laravel → api.anthropic.com → Browser
 *
 * The API key lives in .env (ANTHROPIC_API_KEY) and is never sent to the client.
 *
 * RATE LIMITING
 * ─────────────
 * 10 requests per minute per authenticated user. This prevents abuse in case
 * a student tries to spam the endpoint. The rate limit key is the user's ID.
 *
 * AUTH
 * ────
 * Route is guarded by the 'auth' middleware — unauthenticated users cannot
 * reach this endpoint. The API key is therefore never accessible to the public.
 *
 * ALLOWED USE CASES
 * ─────────────────
 * Currently used ONLY for payment proof image validation in ProofUpload.vue.
 * The request body is validated and only the fields needed for that use case
 * are forwarded — arbitrary Anthropic API calls are NOT relayed.
 */
class AiProxyController extends Controller
{
    /**
     * Validate a payment proof image using Claude's vision capability.
     *
     * Accepts a base64-encoded image and a media type, forwards to
     * claude-sonnet-4-20250514, and returns the structured JSON result.
     *
     * POST /ai/verify-proof
     */
    public function verifyPaymentProof(Request $request): JsonResponse
    {
        // ── Rate limiting ──────────────────────────────────────────────────────
        $key = 'ai-proof-verify:' . $request->user()->id;

        if (RateLimiter::tooManyAttempts($key, 10)) {
            $seconds = RateLimiter::availableIn($key);
            return response()->json([
                'error' => "Too many verification requests. Please wait {$seconds} seconds.",
            ], 429);
        }

        RateLimiter::hit($key, 60); // 60-second window

        // ── Input validation ───────────────────────────────────────────────────
        $validated = $request->validate([
            'image'      => ['required', 'string'],   // base64-encoded image data
            'media_type' => ['required', 'string', 'in:image/jpeg,image/png,image/webp,image/gif'],
        ]);

        $apiKey = config('services.anthropic.api_key');

        if (empty($apiKey)) {
            Log::error('AiProxyController: ANTHROPIC_API_KEY is not set in .env');
            return response()->json([
                'error' => 'AI verification is not configured on this server.',
            ], 503);
        }

        // ── Forward to Anthropic ───────────────────────────────────────────────
        try {
            $response = Http::withHeaders([
                'x-api-key'         => $apiKey,
                'anthropic-version' => '2023-06-01',
                'Content-Type'      => 'application/json',
            ])->timeout(30)->post('https://api.anthropic.com/v1/messages', [
                'model'      => 'claude-sonnet-4-20250514',
                'max_tokens' => 256,
                'messages'   => [
                    [
                        'role' => 'user',
                        'content' => [
                            [
                                'type'   => 'image',
                                'source' => [
                                    'type'       => 'base64',
                                    'media_type' => $validated['media_type'],
                                    'data'       => $validated['image'],
                                ],
                            ],
                            [
                                'type' => 'text',
                                'text' => <<<'PROMPT'
You are a payment verification assistant for a school payment system.

Analyze this image and determine if it is a legitimate proof of payment or payment receipt.

A valid proof of payment typically shows one or more of:
- Bank transfer confirmation or transaction receipt
- GCash / Maya / e-wallet transfer confirmation
- Official OR (Official Receipt) from a school or business
- Payment slip with amount, date, and reference number
- Bank deposit slip
- Credit/debit card transaction receipt

An INVALID submission would be:
- Anime or cartoon characters
- Memes, screenshots of social media, or unrelated photos
- Selfies, food photos, or any non-payment image
- Blank images or test images

Respond ONLY in this JSON format with no extra text:
{"result": "valid" | "invalid" | "uncertain", "reason": "brief one-sentence explanation"}

- "valid" = clearly a payment receipt/proof
- "invalid" = clearly NOT a payment receipt (e.g. anime, meme, random photo)
- "uncertain" = could be a receipt but hard to tell (blurry, partial, etc.)
PROMPT,
                            ],
                        ],
                    ],
                ],
            ]);

            if (! $response->successful()) {
                Log::warning('AiProxyController: Anthropic returned error', [
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ]);

                return response()->json([
                    'result' => 'uncertain',
                    'reason' => 'AI verification service is temporarily unavailable. Our team will review manually.',
                ]);
            }

            $body    = $response->json();
            $rawText = collect($body['content'] ?? [])
                ->firstWhere('type', 'text')['text'] ?? '';

            // Parse the structured JSON response from the model.
            try {
                $clean  = trim(preg_replace('/```json|```/', '', $rawText));
                $parsed = json_decode($clean, true, 512, JSON_THROW_ON_ERROR);

                return response()->json([
                    'result' => $parsed['result'] ?? 'uncertain',
                    'reason' => $parsed['reason'] ?? 'Unable to determine.',
                ]);
            } catch (\JsonException $e) {
                Log::warning('AiProxyController: failed to parse model JSON response', [
                    'raw' => $rawText,
                ]);

                return response()->json([
                    'result' => 'uncertain',
                    'reason' => 'AI response could not be interpreted. Our team will review manually.',
                ]);
            }

        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::error('AiProxyController: connection to Anthropic failed', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'result' => 'uncertain',
                'reason' => 'AI service is unreachable. Our team will verify your proof manually.',
            ]);
        }
    }
}