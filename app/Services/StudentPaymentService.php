<?php

namespace App\Services;

use App\Enums\PaymentStatus;
use App\Models\User;
use App\Models\Transaction;
use App\Models\Payment;
use App\Models\Notification;
use App\Models\StudentAssessment;
use App\Models\StudentPaymentTerm;
use App\Services\AccountService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class StudentPaymentService
{
    /**
     * Process a payment for a user starting from a specific payment term.
     *
     * ALLOCATION RULES:
     *   1. Apply payment to selected term first.
     *   2. If payment > selected term balance, excess flows to next terms
     *      sequentially by term_order (ascending).
     *   3. Payment MUST NOT exceed total outstanding balance across all terms.
     *
     * ⚠️ IMPORTANT: When $requiresApproval=true, the CALLER is responsible for
     * starting the approval workflow.
     */
    public function processPayment(User $user, float $amount, array $options, bool $requiresApproval = true): array
    {
        $termId = (int) ($options['selected_term_id'] ?? 0);

        if ($termId === 0) {
            throw new \Exception('A payment term must be selected.');
        }

        $term   = StudentPaymentTerm::findOrFail($termId);
        $amount = round($amount, 2);

        if ($amount <= 0) {
            throw new \Exception('Payment amount must be greater than zero.');
        }

        // ── TOTAL OUTSTANDING GUARD ────────────────────────────────────────────
        // This is the one true ceiling. Payment may flow across terms but never
        // exceed total remaining balance for the entire assessment.
        $totalOutstanding = round(
            StudentPaymentTerm::where('student_assessment_id', $term->student_assessment_id)
                ->whereIn('status', PaymentStatus::unpaidValues())
                ->sum('balance'),
            2
        );

        if ($amount > $totalOutstanding) {
            throw new \Exception(sprintf(
                'Payment amount (₱%s) exceeds total outstanding balance (₱%s).',
                number_format($amount, 2),
                number_format($totalOutstanding, 2)
            ));
        }

        return DB::transaction(function () use ($user, $amount, $options, $term, $requiresApproval, $totalOutstanding) {

            $reference = 'PAY-' . Str::upper(Str::random(8));

            $status = $requiresApproval
                ? PaymentStatus::AWAITING_APPROVAL->value
                : PaymentStatus::PAID->value;

            $description = $options['description'] ?? null;
            if (empty($description)) {
                $description = 'Payment — ' . ($options['term_name'] ?? $term->term_name);
            }

            $meta = [
                'payment_method'    => $options['payment_method'] ?? null,
                'description'       => $description,
                'term_name'         => $options['term_name'] ?? $term->term_name,
                'selected_term_id'  => $term->id,
                'assessment_id'     => $term->student_assessment_id,
                'requires_approval' => $requiresApproval,
            ];

            $transaction = Transaction::create([
                'user_id'         => $user->id,
                'reference'       => $reference,
                'or_number'       => $options['or_number'] ?? null,
                'kind'            => 'payment',
                'type'            => $options['term_name'] ?? $term->term_name,
                'amount'          => $amount,
                'status'          => $status,
                'payment_channel' => $options['payment_method'] ?? null,
                'paid_at'         => $options['paid_at'] ?? now(),
                'year'            => $options['year'] ?? now()->year,
                'semester'        => $options['semester'] ?? null,
                'meta'            => $meta,
            ]);

            // Immediately apply if no approval needed (accounting staff payment)
            if (! $requiresApproval) {
                $allocation = $this->allocatePaymentAcrossTerms($term, $amount);

                foreach ($allocation as $alloc) {
                    if ($user->student) {
                        Payment::create([
                            'student_id'            => $user->student->id,
                            'student_assessment_id' => $term->student_assessment_id,
                            'amount'                => $alloc['applied'],
                            'or_number'             => $options['or_number'] ?? null,
                            'payment_method'        => $options['payment_method'] ?? null,
                            'description'           => 'Payment — ' . $alloc['term_name'],
                            'status'                => PaymentStatus::COMPLETED->value,
                            'created_at'            => $options['paid_at'] ?? now(),
                            'updated_at'            => $options['paid_at'] ?? now(),
                        ]);
                    }
                }

                AccountService::recalculate($user);
                $this->checkAndNotifyProgressionReady($user, $term->student_assessment_id);

                $message = 'Payment of ₱' . number_format($amount, 2) . ' recorded successfully.';
            } else {
                $message = 'Payment of ₱' . number_format($amount, 2) . ' submitted and is awaiting accounting approval.';
            }

            return [
                'transaction_id'        => $transaction->id,
                'transaction_reference' => $reference,
                'message'               => $message,
            ];
        });
    }

    /**
     * Finalize an approved payment by applying it across terms starting from the selected term.
     *
     * ALLOCATION RULES (same as processPayment):
     *   1. Apply to selected term first.
     *   2. Excess flows into subsequent terms by term_order (ascending).
     *   3. Never exceed total outstanding balance.
     *
     * Uses SELECT ... FOR UPDATE to prevent concurrent payment race conditions.
     */
    public function finalizeApprovedPayment(Transaction $transaction): void
    {
        if ($transaction->kind !== 'payment') {
            throw new \Exception('Transaction is not a payment.');
        }

        if ($transaction->status === PaymentStatus::PAID->value) {
            Log::info('finalizeApprovedPayment: already paid, skipping', [
                'transaction_id' => $transaction->id,
            ]);
            return;
        }

        $amount = round((float) $transaction->amount, 2);

        if ($amount <= 0) {
            Log::error('finalizeApprovedPayment: zero amount — aborting', [
                'transaction_id' => $transaction->id,
                'amount'         => $amount,
            ]);
            throw new \Exception(
                "Cannot finalize transaction #{$transaction->id}: amount is ₱0.00. " .
                'Please correct the transaction amount before approving.'
            );
        }

        DB::transaction(function () use ($transaction, $amount) {
            $user = $transaction->user;

            // ── Resolve the starting term ──────────────────────────────────────
            $termId = isset($transaction->meta['selected_term_id'])
                ? (int) $transaction->meta['selected_term_id']
                : null;

            $term = null;

            if ($termId) {
                $term = StudentPaymentTerm::lockForUpdate()->find($termId);
            }

            // Fallback: match by term name if ID not in meta
            if (! $term) {
                $termName = $transaction->meta['term_name'] ?? $transaction->type;

                Log::warning('finalizeApprovedPayment: term_id missing in meta, falling back to name match', [
                    'transaction_id' => $transaction->id,
                    'term_name'      => $termName,
                    'user_id'        => $user->id,
                ]);

                $term = StudentPaymentTerm::whereHas('assessment', function ($q) use ($user) {
                        $q->where('user_id', $user->id);
                    })
                    ->where('term_name', $termName)
                    ->whereIn('status', PaymentStatus::unpaidValues())
                    ->orderBy('due_date', 'desc')
                    ->lockForUpdate()
                    ->first();
            }

            if (! $term) {
                throw new \Exception(
                    "Could not find StudentPaymentTerm for transaction #{$transaction->id} (user {$user->id}). " .
                    'Payment cannot be finalized without a term reference.'
                );
            }

            // ── TOTAL OUTSTANDING GUARD (final safety net) ─────────────────────
            $totalOutstanding = round(
                StudentPaymentTerm::where('student_assessment_id', $term->student_assessment_id)
                    ->whereIn('status', PaymentStatus::unpaidValues())
                    ->lockForUpdate()
                    ->sum('balance'),
                2
            );

            if ($amount > $totalOutstanding + 0.01) {
                // Clamp and log — accounting already approved, do not throw.
                // Manual reconciliation required for the excess.
                Log::error('finalizeApprovedPayment: amount exceeds total outstanding — clamping to prevent overpayment', [
                    'transaction_id'   => $transaction->id,
                    'amount'           => $amount,
                    'total_outstanding'=> $totalOutstanding,
                    'excess'           => round($amount - $totalOutstanding, 2),
                ]);
                $amount = $totalOutstanding;
            }

            // ── Allocate payment across terms sequentially ─────────────────────
            $allocation = $this->allocatePaymentAcrossTerms($term, $amount);

            // ── Create Payment audit records per term ──────────────────────────
            foreach ($allocation as $alloc) {
                if ($user->student) {
                    // For bank transfers the reference lives in meta->reference_number
                    // (or_number on the Transaction is null). Copy it into Payment.or_number
                    // so the payments table has the correct identifier going forward.
                    $paymentOrNumber = $transaction->or_number
                        ?? ($transaction->meta['reference_number'] ?? null);

                    Payment::create([
                        'student_id'            => $user->student->id,
                        'student_assessment_id' => $term->student_assessment_id,
                        'amount'                => $alloc['applied'],
                        'or_number'             => $paymentOrNumber,
                        'payment_method'        => $transaction->payment_channel,
                        'description'           => 'Payment — ' . $alloc['term_name'],
                        'status'                => PaymentStatus::COMPLETED->value,
                        'created_at'            => $transaction->created_at ?? now(),
                        'updated_at'            => $transaction->created_at ?? now(),
                    ]);
                }
            }

            $totalApplied  = round(array_sum(array_column($allocation, 'applied')), 2);
            $termsLabel    = collect($allocation)->pluck('term_name')->implode(' + ');
            $termsCount    = count($allocation);

            $description = $termsCount > 1
                ? "₱{$totalApplied} allocated across: {$termsLabel}"
                : 'Payment — ' . ($allocation[0]['term_name'] ?? $term->term_name);

            // Backfill year/semester from the assessment if the transaction was
            // created without them (bank transfer flow pre-fix).
            $assessmentForYear = $term->assessment;
            $backfillYear      = $transaction->year
                ?? ($assessmentForYear ? explode('-', $assessmentForYear->school_year)[0] : (string) now()->year);
            $backfillSemester  = $transaction->semester
                ?? $assessmentForYear?->semester;

            $transaction->update([
                'status'   => PaymentStatus::PAID->value,
                'year'     => $backfillYear,
                'semester' => $backfillSemester,
                'meta'     => array_merge($transaction->meta ?? [], [
                    'allocation'     => $allocation,
                    'terms_covered'  => $termsCount,
                    'total_applied'  => $totalApplied,
                    'finalized_at'   => now()->toIso8601String(),
                    'description'    => $description,
                ]),
            ]);

            AccountService::recalculate($user);
            $this->checkAndNotifyProgressionReady($user, $term->student_assessment_id);

            Log::info('Payment finalized with allocation', [
                'transaction_id'  => $transaction->id,
                'starting_term'   => $term->term_name,
                'amount'          => $amount,
                'terms_covered'   => $termsCount,
                'total_applied'   => $totalApplied,
                'allocation'      => $allocation,
            ]);
        });
    }

    /**
     * Cancel a rejected payment by updating the transaction status.
     */
    public function cancelRejectedPayment(Transaction $transaction): void
    {
        if ($transaction->kind !== 'payment') {
            throw new \Exception('Transaction is not a payment.');
        }

        DB::transaction(function () use ($transaction) {
            $transaction->update(['status' => PaymentStatus::CANCELLED->value]);

            Log::info('Payment cancelled due to workflow rejection', [
                'transaction_id' => $transaction->id,
                'amount'         => $transaction->amount,
                'reference'      => $transaction->reference,
            ]);
        });
    }

    /**
     * Get the total outstanding balance for a user, derived from their payment terms.
     */
    public function getTotalOutstandingBalance(User $user): float
    {
        return round((float) StudentPaymentTerm::whereHas('assessment', function ($q) use ($user) {
            $q->where('user_id', $user->id);
        })
            ->whereIn('status', PaymentStatus::unpaidValues())
            ->sum('balance'), 2);
    }

    /**
     * Public proxy for checkAndNotifyProgressionReady.
     */
    public function notifyProgressionIfComplete(User $user, int $assessmentId): void
    {
        $this->checkAndNotifyProgressionReady($user, $assessmentId);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PRIVATE: Sequential allocation engine
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Allocate a payment amount starting at $startTerm, then flowing into
     * subsequent terms ordered by term_order ASC.
     *
     * Each term gets min(remaining, term.balance).
     * The loop stops when remaining hits zero.
     *
     * Returns an allocation ledger array, one entry per affected term:
     *   [term_id, term_name, term_order, applied, balance_before, balance_after, status_after]
     *
     * SIDE EFFECT: updates StudentPaymentTerm records in the database.
     * Must be called inside a DB::transaction().
     */
    private function allocatePaymentAcrossTerms(StudentPaymentTerm $startTerm, float $amount): array
    {
        $allocation = [];
        $remaining  = round($amount, 2);

        // Load starting term + all subsequent unpaid terms ordered by term_order.
        // Lock rows for update to prevent concurrent modifications.
        $terms = StudentPaymentTerm::where('student_assessment_id', $startTerm->student_assessment_id)
            ->whereIn('status', PaymentStatus::unpaidValues())
            ->where(function ($q) use ($startTerm) {
                $q->where('id', $startTerm->id)
                  ->orWhere('term_order', '>', $startTerm->term_order);
            })
            ->orderBy('term_order', 'asc')
            ->lockForUpdate()
            ->get();

        foreach ($terms as $term) {
            if ($remaining <= 0) {
                break;
            }

            $balanceBefore = round((float) $term->balance, 2);
            $applied       = round(min($remaining, $balanceBefore), 2);
            $balanceAfter  = round($balanceBefore - $applied, 2);

            // Snap to zero to eliminate sub-cent float residue (e.g. 0.000001)
            if ($balanceAfter < 0.01) {
                $balanceAfter = 0.0;
            }

            $newStatus = $balanceAfter <= 0
                ? PaymentStatus::PAID->value
                : PaymentStatus::PARTIAL->value;

            $term->update([
                'balance'   => $balanceAfter,
                'status'    => $newStatus,
                'paid_date' => $newStatus === PaymentStatus::PAID->value ? now() : $term->paid_date,
            ]);

            $allocation[] = [
                'term_id'        => $term->id,
                'term_name'      => $term->term_name,
                'term_order'     => $term->term_order,
                'applied'        => $applied,
                'balance_before' => $balanceBefore,
                'balance_after'  => $balanceAfter,
                'status_after'   => $newStatus,
            ];

            $remaining = round($remaining - $applied, 2);
        }

        if ($remaining > 0.01) {
            // This should never happen — caller checked total outstanding first.
            Log::error('allocatePaymentAcrossTerms: unallocated remainder after exhausting all terms', [
                'start_term_id' => $startTerm->id,
                'amount'        => $amount,
                'remaining'     => $remaining,
                'allocation'    => $allocation,
            ]);
        }

        return $allocation;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PRIVATE: Semester Completion Detection + Admin Notification
    // ─────────────────────────────────────────────────────────────────────────

    private function checkAndNotifyProgressionReady(User $user, int $assessmentId): void
    {
        try {
            $assessment = StudentAssessment::with('paymentTerms')->find($assessmentId);

            if (! $assessment) {
                return;
            }

            $allPaid = $assessment->paymentTerms->isNotEmpty()
                && $assessment->paymentTerms->every(
                    fn ($t) => $t->status === PaymentStatus::PAID->value
                );

            if (! $allPaid) {
                return;
            }

            $alreadyNotified = Notification::where('type', 'progression_ready')
                ->whereJsonContains('term_ids', $assessmentId)
                ->exists();

            if ($alreadyNotified) {
                return;
            }

            $yearLevel   = $assessment->year_level;
            $semester    = $assessment->semester;
            $schoolYear  = $assessment->school_year;
            $studentName = trim($user->first_name . ' ' . $user->last_name);
            $nextLabel   = $this->resolveNextSemesterLabel($yearLevel, $semester);

            Notification::create([
                'title'       => "📋 Assessment Required: {$studentName}",
                'message'     => "{$studentName} (ID: {$user->account_id}) has fully paid their "
                               . "{$yearLevel} {$semester} ({$schoolYear}) assessment. "
                               . "Please create their {$nextLabel} assessment via Student Fees → Create Assessment.",
                'type'        => 'progression_ready',
                'target_role' => 'admin',
                'user_id'     => null,
                'is_active'   => true,
                'is_complete' => false,
                'start_date'  => now()->toDateString(),
                'end_date'    => now()->addDays(30)->toDateString(),
                'term_ids'    => [$assessmentId],
            ]);

            Notification::create([
                'title'       => "✅ {$yearLevel} {$semester} Fully Paid!",
                'message'     => "Congratulations! You have fully settled all payment terms for "
                               . "{$yearLevel} {$semester} ({$schoolYear}). "
                               . "The admin is now preparing your {$nextLabel} assessment. "
                               . 'You will be notified once it is ready.',
                'type'        => 'payment_due',
                'target_role' => 'student',
                'user_id'     => $user->id,
                'is_active'   => true,
                'is_complete' => false,
                'start_date'  => now()->toDateString(),
                'end_date'    => now()->addDays(14)->toDateString(),
            ]);

            Log::info('StudentPaymentService: progression_ready notifications sent', [
                'user_id'       => $user->id,
                'assessment_id' => $assessmentId,
            ]);

        } catch (\Exception $e) {
            Log::error('StudentPaymentService: failed to send progression_ready notification', [
                'user_id'       => $user->id,
                'assessment_id' => $assessmentId,
                'error'         => $e->getMessage(),
            ]);
        }
    }

    private function resolveNextSemesterLabel(string $yearLevel, string $semester): string
    {
        $progression = [
            '1st Year|1st Sem' => '1st Year 2nd Sem',
            '1st Year|2nd Sem' => '2nd Year 1st Sem',
            '2nd Year|1st Sem' => '2nd Year 2nd Sem',
            '2nd Year|2nd Sem' => '3rd Year 1st Sem',
            '3rd Year|1st Sem' => '3rd Year 2nd Sem',
            '3rd Year|2nd Sem' => '4th Year 1st Sem',
            '4th Year|1st Sem' => '4th Year 2nd Sem',
            '4th Year|2nd Sem' => 'graduation (program completed)',
        ];

        return $progression["{$yearLevel}|{$semester}"] ?? 'next semester';
    }
}