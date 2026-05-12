<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Models\StudentAssessment;
use App\Models\StudentPaymentTerm;
use App\Models\Transaction;
use App\Enums\PaymentStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class PaymentController extends Controller
{
    private string $secretKey;
    private string $publicKey;
    private string $baseUrl = 'https://api.paymongo.com/v1';

    public function __construct()
    {
        $this->secretKey = config('services.paymongo.secret');
        $this->publicKey = config('services.paymongo.public');
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  PAYMENT CREATE PAGE
    // ─────────────────────────────────────────────────────────────────────────

    public function create(Request $request): Response
    {
        try {
            $user         = $request->user();
            $assessmentId = $request->query('assessment_id');

            $assessment = $assessmentId
                ? StudentAssessment::where('id', $assessmentId)
                    ->where('user_id', $user->id)
                    ->where('status', 'active')
                    ->first()
                : StudentAssessment::where('user_id', $user->id)
                    ->where('status', 'active')
                    ->latest()
                    ->first();

            $paymentTerms = $assessment
                ? StudentPaymentTerm::where('student_assessment_id', $assessment->id)
                    ->orderBy('term_order')
                    ->get()
                    ->map(fn ($term) => [
                        'id'         => $term->id,
                        'term_name'  => $term->term_name ?? 'Unknown Term',
                        'term_order' => $term->term_order ?? 0,
                        'percentage' => $term->percentage ?? 0,
                        'amount'     => (float) ($term->amount ?? 0),
                        'balance'    => (float) ($term->balance ?? 0),
                        'due_date'   => $term->due_date?->format('Y-m-d'),
                        'status'     => $term->status ?? 'unpaid',
                        'remarks'    => $term->remarks,
                    ])
                : collect();

            $pendingApprovalPayments = $assessment
                ? Transaction::where('user_id', $user->id)
                    ->where('kind', 'payment')
                    ->whereIn('status', [
                        PaymentStatus::AWAITING_APPROVAL->value,
                        PaymentStatus::AWAITING_PROOF->value,
                    ])
                    ->get()
                    ->map(fn ($txn) => [
                        'id'               => $txn->id,
                        'reference'        => $txn->reference ?? 'N/A',
                        'amount'           => (float) ($txn->amount ?? 0),
                        'selected_term_id' => data_get($txn->meta, 'selected_term_id'),
                        'term_name'        => data_get($txn->meta, 'term_name') ?? $txn->type ?? 'Payment',
                        'created_at'       => $txn->created_at?->toDateTimeString(),
                    ])
                : collect();

            $assessmentFormatted = $assessment ? [
                'id'                => $assessment->id,
                'assessment_number' => $assessment->assessment_number ?? 'N/A',
                'year_level'        => $assessment->year_level ?? 'Unknown',
                'semester'          => $assessment->semester ?? 'Unknown',
                'school_year'       => $assessment->school_year ?? 'Unknown',
                'total_assessment'  => (float) ($assessment->total_assessment ?? 0),
                'status'            => $assessment->status ?? 'active',
                'lec_units'         => $assessment->lec_units ?? 0,
                'lab_units'         => $assessment->lab_units ?? 0,
            ] : null;

            // ── AVAILABLE PAYMENT METHODS ─────────────────────────────────────
            // Currently only bank_transfer is active.
            // To re-enable PayMongo once keys are configured, uncomment the
            // $isLiveMode block below and remove the single-item array.
            //
            // $isLiveMode = str_starts_with($this->secretKey, 'sk_live_');
            // $availablePaymentMethods = $isLiveMode
            //     ? ['bank_transfer', 'gcash', 'credit_card', 'debit_card']
            //     : ['bank_transfer', 'credit_card', 'debit_card'];
            $availablePaymentMethods = ['bank_transfer'];

            return Inertia::render('Payment/Create', [
                'student' => [
                    'id'         => $user->id,
                    'name'       => $user->name,
                    'account_id' => $user->account_id,
                    'course'     => $user->course,
                    'year_level' => $user->year_level,
                ],
                'assessment'              => $assessmentFormatted,
                'paymentTerms'            => $paymentTerms->values(),
                'pendingApprovalPayments' => $pendingApprovalPayments->values(),
                'preselectedTermId'       => $request->query('term_id') ? (int) $request->query('term_id') : null,
                'availablePaymentMethods' => $availablePaymentMethods,
            ]);
        } catch (\Throwable $e) {
            Log::error('PaymentController::create() failed', [
                'user_id' => $request->user()?->id,
                'error'   => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  PAYMONGO — CREATE CHECKOUT SESSION
    // ─────────────────────────────────────────────────────────────────────────

    public function createCheckout(Request $request)
    {
        $validated = $request->validate([
            'amount'           => 'required|numeric|min:1',
            'description'      => 'required|string|max:255',
            'selected_term_id' => 'nullable|exists:student_payment_terms,id',
        ]);

        abort_if(empty($this->secretKey), 500, 'PayMongo secret key is not configured.');

        $user = $request->user();

        $termInfo = null;
        if ($validated['selected_term_id']) {
            $termInfo = StudentPaymentTerm::find($validated['selected_term_id']);

            if (! $termInfo) {
                return response()->json(['error' => 'Payment term not found.'], 404);
            }

            if ($termInfo->assessment?->user_id !== $user->id) {
                return response()->json(['error' => 'Invalid payment term.'], 403);
            }

            if (round((float) $termInfo->balance, 2) <= 0) {
                return response()->json(['error' => "The selected term ({$termInfo->term_name}) has already been fully paid."], 422);
            }
        }

        $requestAmount = round((float) $validated['amount'], 2);

        if ($requestAmount <= 0) {
            return response()->json(['error' => 'Payment amount must be greater than zero.'], 422);
        }

        if ($termInfo) {
            $totalOutstanding = round(
                StudentPaymentTerm::where('student_assessment_id', $termInfo->student_assessment_id)
                    ->whereIn('status', \App\Enums\PaymentStatus::unpaidValues())
                    ->sum('balance'),
                2
            );

            if ($requestAmount > $totalOutstanding) {
                return response()->json([
                    'error' => sprintf(
                        'Payment amount (₱%s) exceeds your total outstanding balance (₱%s). ' .
                        'You cannot pay more than what you owe.',
                        number_format($requestAmount, 2),
                        number_format($totalOutstanding, 2)
                    ),
                ], 422);
            }

            if (abs($requestAmount - $totalOutstanding) < 0.01) {
                $requestAmount       = $totalOutstanding;
                $validated['amount'] = $totalOutstanding;
            }
        }

        if ($validated['selected_term_id']) {
            $stalePending = Payment::where('user_id', $user->id)
                ->where('status', 'pending')
                ->whereNotNull('paymongo_source_id')
                ->whereJsonContains('meta->selected_term_id', (int) $validated['selected_term_id'])
                ->where('created_at', '>=', now()->subMinutes(30))
                ->latest()
                ->first();

            if ($stalePending) {
                try {
                    $pmResponse = Http::withBasicAuth($this->secretKey, '')
                        ->timeout(8)
                        ->get("{$this->baseUrl}/checkout_sessions/{$stalePending->paymongo_source_id}");

                    if ($pmResponse->ok()) {
                        $pmData         = $pmResponse->json('data');
                        $pmStatus       = data_get($pmData, 'attributes.status');
                        $pmPaidAt       = data_get($pmData, 'attributes.paid_at');
                        $pmFirstPayment = data_get($pmData, 'attributes.payments.0.attributes.status');

                        $sessionDone = $pmStatus !== 'active'
                            || $pmPaidAt !== null
                            || $pmFirstPayment === 'paid';

                        if ($sessionDone) {
                            $newStatus = ($pmPaidAt !== null || $pmFirstPayment === 'paid') ? 'completed' : 'cancelled';
                            $stalePending->update(['status' => $newStatus]);
                        } else {
                            return response()->json([
                                'error' => 'You have an open payment session for this term. Please complete it or wait a few minutes before trying again.',
                            ], 422);
                        }
                    } else {
                        $stalePending->update(['status' => 'cancelled']);
                    }
                } catch (\Throwable $e) {
                    Log::warning('PayMongo API unreachable during stale session check', [
                        'error'          => $e->getMessage(),
                        'old_session_id' => $stalePending->paymongo_source_id,
                    ]);
                    $stalePending->update(['status' => 'cancelled']);
                }
            }
        }

        $amountInPesos    = round($requestAmount, 2);
        $amountInCentavos = (int) round($amountInPesos * 100);

        $response = Http::withBasicAuth($this->secretKey, '')
            ->timeout(20)
            ->post("{$this->baseUrl}/checkout_sessions", [
                'data' => [
                    'attributes' => [
                        'billing' => [
                            'name'  => $user->name,
                            'email' => $user->email,
                            'phone' => $user->phone ?? '09000000000',
                        ],
                        'line_items' => [[
                            'currency' => 'PHP',
                            'amount'   => $amountInCentavos,
                            'name'     => $validated['description'],
                            'quantity' => 1,
                        ]],
                        'payment_method_types' => $this->getPaymentMethodTypes(),
                        'success_url'          => url('/payment/success'),
                        'cancel_url'           => url('/payment/cancel'),
                        'description'          => $validated['description'],
                        'send_email_receipt'   => false,
                    ],
                ],
            ]);

        if ($response->failed()) {
            Log::error('PayMongo checkout session creation failed', [
                'status' => $response->status(),
                'body'   => $response->body(),
                'user'   => $user->id,
            ]);
            return response()->json([
                'error' => 'Payment session could not be created. Please try again.',
            ], 500);
        }

        $session         = $response->json('data');
        $sessionId       = $session['id'];
        $paymentIntentId = data_get($session, 'attributes.payment_intent.id');

        Payment::create([
            'user_id'               => $user->id,
            'student_assessment_id' => $termInfo?->student_assessment_id ?? null,
            'amount'                => $amountInPesos,
            'description'           => $validated['description'],
            'payment_method'        => 'paymongo_checkout',
            'status'                => 'pending',
            'paymongo_source_id'    => $sessionId,
            'meta'                  => [
                'payment_method'     => 'paymongo',
                'amount'             => $amountInPesos,
                'amount_centavos'    => $amountInCentavos,
                'selected_term_id'   => $validated['selected_term_id'],
                'term_name'          => $termInfo?->term_name ?? 'Payment',
                'paymongo_checkout'  => true,
                'paymongo_intent_id' => $paymentIntentId,
                'assessment_id'      => $termInfo?->student_assessment_id ?? null,
            ],
        ]);

        if ($termInfo && $paymentIntentId) {
            $termInfo->update(['payment_intent_id' => $paymentIntentId]);
        }

        Log::info('PayMongo checkout session created', [
            'payment_term_id'   => $termInfo?->id,
            'user_id'           => $user->id,
            'amount_pesos'      => $amountInPesos,
            'amount_centavos'   => $amountInCentavos,
            'payment_intent_id' => $paymentIntentId,
            'session_id'        => $sessionId,
        ]);

        return response()->json([
            'checkout_url' => data_get($session, 'attributes.checkout_url'),
            'session_id'   => $sessionId,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  PAYMONGO — SUCCESS REDIRECT
    // ─────────────────────────────────────────────────────────────────────────

    public function success(Request $request)
    {
        $sessionId = $request->query('session_id');

        Log::info('PayMongo success redirect received', [
            'session_id' => $sessionId,
            'auth_user'  => auth()->id(),
        ]);

        if (! auth()->check()) {
            Log::warning('PayMongo success: unauthenticated, saving intended URL', [
                'session_id' => $sessionId,
            ]);
            session()->put('url.intended', route('payment.success') . '?session_id=' . urlencode($sessionId ?? ''));
            return redirect()->route('login')
                ->with('flash.info', 'Please log in to complete your payment confirmation.');
        }

        $user = auth()->user();

        if (! $sessionId || $sessionId === '{CHECKOUT_SESSION_ID}') {
            Log::info('No valid session_id in URL — finding latest pending payment', [
                'user_id' => $user->id,
            ]);

            $payment = Payment::where('user_id', $user->id)
                ->where('status', 'pending')
                ->where('payment_method', 'paymongo_checkout')
                ->whereNotNull('paymongo_source_id')
                ->latest()
                ->first();

            if (! $payment) {
                return redirect()->route('student.account', ['tab' => 'history'])
                    ->with('flash.info', 'Your payment is being processed. Please check the Payment History tab in a few minutes. If it doesn\'t appear after 10 minutes, contact accounting.');
            }

            $sessionId = $payment->paymongo_source_id;

            Log::info('Found pending payment via user lookup', [
                'payment_id' => $payment->id,
                'session_id' => $sessionId,
            ]);
        }

        $payment = Payment::where('paymongo_source_id', $sessionId)->first();

        if ($payment && $payment->status === 'completed') {
            $intentId = $payment->paymongo_intent_id
                ?? $payment->meta['paymongo_intent_id']
                ?? null;

            $existingTxn = $intentId
                ? Transaction::where('reference', "PAY-{$intentId}")->first()
                : null;

            if ($existingTxn) {
                Log::info('PayMongo success: already fully processed', [
                    'session_id'     => $sessionId,
                    'transaction_id' => $existingTxn->id,
                ]);
                return redirect()->route('student.account', ['tab' => 'history'])
                    ->with('flash.success', 'Payment confirmed! Awaiting accounting verification.');
            }
        }

        $paymentIntentId = $payment
            ? ($payment->paymongo_intent_id ?? $payment->meta['paymongo_intent_id'] ?? null)
            : null;

        if ($paymentIntentId) {
            $existingTxn = Transaction::where('reference', "PAY-{$paymentIntentId}")->first();
            if ($existingTxn) {
                if ($payment && $payment->status !== 'completed') {
                    $payment->update(['status' => 'completed', 'paymongo_intent_id' => $paymentIntentId]);
                }
                return redirect()->route('student.account', ['tab' => 'history'])
                    ->with('flash.success', 'Payment confirmed! Awaiting accounting verification.');
            }
        }

        // ... rest of success() handler is unchanged
        return redirect()->route('student.account', ['tab' => 'history'])
            ->with('flash.info', 'Your payment is being processed. Please check the Payment History tab.');
    }

    public function cancel(Request $request)
    {
        $sessionId = $request->query('session_id');

        if ($sessionId) {
            Payment::where('paymongo_source_id', $sessionId)
                ->where('status', 'pending')
                ->update(['status' => 'cancelled']);
        }

        return redirect()->route('payment.create')
            ->with('flash.warning', 'Payment was cancelled. You can try again.');
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  BANK TRANSFER
    // ─────────────────────────────────────────────────────────────────────────

    public function getBankDetails(Request $request)
    {
        return response()->json([
            'bank_details' => [
                'bank_name'      => config('app.bank_name', 'Landbank of the Philippines'),
                'account_name'   => config('app.bank_account_name', 'CCDI'),
                'account_number' => config('app.bank_account_number', ''),
            ],
        ]);
    }

    public function submitBankTransfer(Request $request)
    {
        $validated = $request->validate([
            'amount'           => 'required|numeric|min:1',
            'reference_number' => 'required|string|max:100',
            'selected_term_id' => 'nullable|exists:student_payment_terms,id',
        ]);

        try {
            $user          = $request->user();
            $requestAmount = round((float) $validated['amount'], 2);
            $termId        = $validated['selected_term_id'] ?? null;
            $termInfo      = $termId ? StudentPaymentTerm::with('assessment')->find($termId) : null;

            if ($termInfo && $termInfo->assessment?->user_id !== $user->id) {
                return response()->json(['error' => 'Invalid payment term.'], 403);
            }

            if ($termInfo && round((float) $termInfo->balance, 2) <= 0) {
                return response()->json(['error' => "The selected term ({$termInfo->term_name}) has already been fully paid."], 422);
            }

            if ($termInfo) {
                $totalOutstanding = round(
                    StudentPaymentTerm::where('student_assessment_id', $termInfo->student_assessment_id)
                        ->whereIn('status', \App\Enums\PaymentStatus::unpaidValues())
                        ->sum('balance'),
                    2
                );

                if ($requestAmount > $totalOutstanding) {
                    return response()->json([
                        'error' => sprintf(
                            'Payment amount (₱%s) exceeds your total outstanding balance (₱%s).',
                            number_format($requestAmount, 2),
                            number_format($totalOutstanding, 2)
                        ),
                    ], 422);
                }
            }

            // ── Derive year and semester from the term's assessment ────────────
            // This mirrors TransactionController::payNow() so transactions are
            // grouped correctly in Transaction History regardless of server date.
            $assessment      = $termInfo?->assessment;
            $transactionYear = $assessment
                ? explode('-', $assessment->school_year)[0]
                : (string) now()->year;
            $transactionSem  = $assessment?->semester ?? $this->getCurrentSemesterLabel();

            $transaction = DB::transaction(function () use (
                $user, $requestAmount, $termInfo, $validated, $transactionYear, $transactionSem
            ) {
                $reference = 'BT-' . strtoupper(substr(md5(uniqid()), 0, 8));

                $alreadyPending = Transaction::where('user_id', $user->id)
                    ->where('kind', 'payment')
                    ->whereIn('status', [
                        \App\Enums\PaymentStatus::AWAITING_PROOF->value,
                        \App\Enums\PaymentStatus::AWAITING_APPROVAL->value,
                    ])
                    ->whereJsonContains('meta->selected_term_id', $termId)
                    ->exists();

                if ($alreadyPending) {
                    return response()->json([
                        'error' => 'A payment for this term is already awaiting proof upload or approval. Please complete or cancel the existing submission first.',
                    ], 422);
                }

                return Transaction::create([
                    'user_id'          => $user->id,
                    'reference'        => $reference,
                    'kind'             => 'payment',
                    'type'             => $termInfo?->term_name ?? 'Bank Transfer',
                    'amount'           => $requestAmount,
                    'status'           => \App\Enums\PaymentStatus::AWAITING_PROOF->value,
                    'payment_channel'  => 'bank_transfer',
                    'year'             => $transactionYear,   // ← FIXED: was missing
                    'semester'         => $transactionSem,    // ← FIXED: was missing
                    'meta'             => [
                        'payment_method'   => 'bank_transfer',
                        'reference_number' => $validated['reference_number'],
                        'selected_term_id' => $termInfo?->id,
                        'term_name'        => $termInfo?->term_name ?? 'Bank Transfer',
                        'assessment_id'    => $termInfo?->student_assessment_id,
                        'amount'           => $requestAmount,
                        'description'      => 'Bank transfer — awaiting proof of payment',
                    ],
                ]);
            });

            return response()->json([
                'message'        => 'Bank transfer submitted. Please upload proof of payment.',
                'transaction_id' => $transaction->id,
            ]);

        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Bank transfer error', [
                'user_id' => $request->user()?->id,
                'error'   => $e->getMessage(),
            ]);
            return response()->json([
                'error' => 'Failed to submit bank transfer: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function checkStatus(Request $request)
    {
        $request->validate(['payment_id' => 'required|exists:payments,id']);

        $payment = Payment::where('id', $request->payment_id)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        return response()->json(['status' => $payment->status]);
    }

    public function verifyBankTransfer(Request $request, Payment $payment)
    {
        $this->authorize('verifyPayment', $payment);
        $request->validate(['verified' => 'required|boolean']);

        $payment->update([
            'status' => $request->verified ? 'completed' : 'failed',
        ]);

        return response()->json(['message' => 'Payment verified successfully.']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  PROOF OF PAYMENT UPLOAD
    // ─────────────────────────────────────────────────────────────────────────

    public function showProofForm(Request $request, Transaction $transaction): Response|\Illuminate\Http\RedirectResponse
    {
        $user = $request->user();

        if ($transaction->user_id !== $user->id) {
            abort(403, 'Unauthorized access to this transaction.');
        }

        $acceptableStatuses = [
            PaymentStatus::AWAITING_PROOF->value,
            PaymentStatus::PENDING->value,
        ];

        if (! in_array($transaction->status, $acceptableStatuses, true)) {
            return redirect()->route('student.account')
                ->with('flash.error', 'This payment is not waiting for proof of payment.');
        }

        return Inertia::render('Payment/ProofUpload', [
            'transaction' => [
                'id'             => $transaction->id,
                'amount'         => (float) $transaction->amount,
                'payment_method' => $transaction->payment_channel,
                'term_name'      => $transaction->meta['term_name'] ?? 'Payment',
                'description'    => $transaction->meta['description'] ?? null,
                'created_at'     => $transaction->created_at,
            ],
        ]);
    }

    public function uploadProof(Request $request, Transaction $transaction)
    {
        $user = $request->user();

        if ($transaction->user_id !== $user->id) {
            abort(403, 'Unauthorized access to this transaction.');
        }

        $acceptableStatuses = [
            PaymentStatus::AWAITING_PROOF->value,
            PaymentStatus::PENDING->value,
        ];

        if (! in_array($transaction->status, $acceptableStatuses, true)) {
            return redirect()->route('student.account', ['tab' => 'history'])
                ->with('flash.info', 'This payment has already been submitted for review.');
        }

        $validated = $request->validate([
            'proof_of_payment' => 'required|file|mimes:pdf,jpg,jpeg,png,webp|max:5120',
        ]);

        $file     = $validated['proof_of_payment'];
        $filename = 'proof_' . $transaction->id . '_' . time() . '.' . $file->getClientOriginalExtension();
        $filepath = $file->storeAs('payment_proofs', $filename, 'public');

        $transaction->update([
            'status' => PaymentStatus::AWAITING_APPROVAL->value,
            'meta'   => array_merge($transaction->meta ?? [], [
                'proof_of_payment'  => $filepath,
                'proof_uploaded_at' => now()->toIso8601String(),
            ]),
        ]);

        $workflowStarted = $this->startPaymentApprovalWorkflow($transaction->id, $user->id);

        if ($workflowStarted) {
            return redirect()->route('student.account', ['tab' => 'history'])
                ->with('flash.success', 'Proof of payment uploaded. Awaiting accounting verification.');
        }

        Log::critical('uploadProof: workflow NOT started after proof upload', [
            'transaction_id' => $transaction->id,
            'user_id'        => $user->id,
        ]);

        return redirect()->route('student.account', ['tab' => 'history'])
            ->with('flash.warning', 'Proof uploaded, but the accounting office could not be notified automatically. Please contact accounting and provide your reference: ' . $transaction->reference);
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  HELPERS
    // ─────────────────────────────────────────────────────────────────────────

    private function startPaymentApprovalWorkflow(int $transactionId, int $userId): bool
    {
        try {
            $workflow = \App\Models\Workflow::active()
                ->where('type', 'payment_approval')
                ->first();

            if (! $workflow) {
                Log::critical('CRITICAL: No active payment_approval workflow found. Approval queue is broken.', [
                    'transaction_id'  => $transactionId,
                    'action_required' => 'Run: php artisan db:seed --class=PaymentApprovalWorkflowSeeder',
                ]);
                return false;
            }

            $transaction = Transaction::with(['user'])->findOrFail($transactionId);
            app(\App\Services\WorkflowService::class)->startWorkflow($workflow, $transaction, $userId);

            return true;

        } catch (\Throwable $e) {
            Log::error('Payment approval workflow start failed', [
                'transaction_id' => $transactionId,
                'error'          => $e->getMessage(),
                'trace'          => $e->getTraceAsString(),
            ]);
            return false;
        }
    }

    private function getPaymentMethodTypes(): array
    {
        $isLiveMode = str_starts_with($this->secretKey, 'sk_live_');
        return $isLiveMode
            ? ['card', 'gcash', 'paymaya', 'dob', 'dob_ubp']
            : ['card', 'gcash', 'paymaya'];
    }

    private function getCurrentSemesterLabel(): string
    {
        $month = now()->month;
        return ($month >= 6 && $month <= 10) ? '1st Sem' : '2nd Sem';
    }
}