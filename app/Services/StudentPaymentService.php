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
use App\Services\MoneyService;
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
     *   3. If payment < selected term balance AND a next term exists, the
     *      remaining unpaid balance is carried forward to the next term. The
     *      current term is CLOSED (status = 'processed', balance = 0). This
     *      is the one-time term processing rule.
     *   4. If payment < selected term balance AND this is the FINAL term (no
     *      next term), the remaining balance stays on this term with status =
     *      'underpaid'. The term is NOT closed. The student must pay the
     *      remainder in a future transaction.
     *   5. Payment MUST NOT exceed total outstanding balance across all terms.
     *
     * PRECISION: All arithmetic is performed in integer cents via MoneyService.
     * No floating-point arithmetic occurs in this method or its callees.
     */
    public function processPayment(User $user, float|int|string $amount, array $options, bool $requiresApproval = true): array
    {
        $termId = (int) ($options['selected_term_id'] ?? 0);

        if ($termId === 0) {
            throw new \Exception('A payment term must be selected.');
        }

        $term = StudentPaymentTerm::findOrFail($termId);

        // Validate that the selected term is actually payable.
        // PROCESSED terms have balance = 0 and are closed — they cannot receive payment.
        if ($term->status === PaymentStatus::PROCESSED->value) {
            $nextTerm = StudentPaymentTerm::where('student_assessment_id', $term->student_assessment_id)
                ->where('balance', '>', 0)
                ->orderBy('term_order')
                ->first();

            throw new \Exception(sprintf(
                'The %s term has already been processed. %s',
                $term->term_name,
                $nextTerm
                    ? 'Your next payable term is: ' . $nextTerm->term_name . '.'
                    : 'All terms have been settled.'
            ));
        }

        // Convert to integer cents at the input boundary — all further arithmetic is exact.
        $amountCents = MoneyService::roundToCents($amount);

        if ($amountCents <= 0) {
            throw new \Exception('Payment amount must be greater than zero.');
        }

        // TOTAL OUTSTANDING GUARD — one true ceiling.
        // Filter by balance > 0 (not by status) because status can be stale.
        // balance is always the authoritative remaining amount per term.
        $outstandingCents = MoneyService::sumFromDb(
            StudentPaymentTerm::where('student_assessment_id', $term->student_assessment_id)
                ->where('balance', '>', 0)
                ->sum('balance')
        );

        if ($amountCents > $outstandingCents) {
            throw new \Exception(sprintf(
                'Payment amount (%s) exceeds total outstanding balance (%s).',
                MoneyService::formatFromCents($amountCents),
                MoneyService::formatFromCents($outstandingCents)
            ));
        }

        return DB::transaction(function () use ($user, $amountCents, $options, $term, $requiresApproval) {

            $reference     = 'PAY-' . Str::upper(Str::random(8));
            $amountDecimal = MoneyService::toPesos($amountCents);

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
                'amount'          => $amountDecimal,
                'status'          => $status,
                'payment_channel' => $options['payment_method'] ?? null,
                'paid_at'         => $options['paid_at'] ?? now(),
                'year'            => $options['year'] ?? now()->year,
                'semester'        => $options['semester'] ?? null,
                'meta'            => $meta,
            ]);

            if (! $requiresApproval) {
                // Direct OTC payment — apply allocation immediately.
                $allocation = $this->allocatePaymentAcrossTerms($term, $amountCents);

                foreach ($allocation as $alloc) {
                    if ($user->student) {
                        Payment::create([
                            'student_id'            => $user->student->id,
                            'student_assessment_id' => $term->student_assessment_id,
                            'amount'                => $alloc['applied_decimal'],
                            'or_number'             => $options['or_number'] ?? null,
                            'payment_method'        => $options['payment_method'] ?? null,
                            'description'           => 'Payment — ' . $alloc['term_name'],
                            'status'                => PaymentStatus::COMPLETED->value,
                            'created_at'            => $options['paid_at'] ?? now(),
                            'updated_at'            => $options['paid_at'] ?? now(),
                        ]);
                    }
                }

                // Write the full allocation breakdown to the transaction meta.
                // This is used by the receipt PDF and audit logs.
                $transaction->update([
                    'meta' => array_merge($transaction->meta ?? [], [
                        'allocation'    => $allocation,
                        'terms_covered' => count($allocation),
                        'total_applied' => $amountDecimal,
                        'finalized_at'  => now()->toIso8601String(),
                    ]),
                ]);

                AccountService::recalculate($user);
                $this->checkAndNotifyProgressionReady($user, $term->student_assessment_id);

                $message = 'Payment of ' . MoneyService::formatFromCents($amountCents) . ' recorded successfully.';
            } else {
                $message = 'Payment of ' . MoneyService::formatFromCents($amountCents) . ' submitted and is awaiting accounting approval.';
            }

            return [
                'transaction_id'        => $transaction->id,
                'transaction_reference' => $reference,
                'message'               => $message,
            ];
        });
    }

    /**
     * Finalize an approved payment by applying it across terms.
     *
     * Uses SELECT ... FOR UPDATE to prevent concurrent payment race conditions.
     * All arithmetic is integer-cents via MoneyService.
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

        $amountCents = MoneyService::roundToCents($transaction->amount);

        if ($amountCents <= 0) {
            Log::error('finalizeApprovedPayment: zero amount — aborting', [
                'transaction_id' => $transaction->id,
                'amount'         => $transaction->amount,
            ]);
            throw new \Exception(
                "Cannot finalize transaction #{$transaction->id}: amount is ₱0.00. " .
                'Please correct the transaction amount before approving.'
            );
        }

        DB::transaction(function () use ($transaction, $amountCents) {
            $user = $transaction->user;

            $termId = isset($transaction->meta['selected_term_id'])
                ? (int) $transaction->meta['selected_term_id']
                : null;

            $term = null;

            if ($termId) {
                $term = StudentPaymentTerm::lockForUpdate()->find($termId);
            }

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
                    ->where('balance', '>', 0)  // authoritative — status can be stale
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

            $outstandingCents = MoneyService::sumFromDb(
                StudentPaymentTerm::where('student_assessment_id', $term->student_assessment_id)
                    ->where('balance', '>', 0)  // authoritative — status can be stale
                    ->lockForUpdate()
                    ->sum('balance')
            );

            // One-cent tolerance for legitimate DB-level rounding.
            if ($amountCents > $outstandingCents + 1) {
                Log::error('finalizeApprovedPayment: amount exceeds total outstanding — clamping', [
                    'transaction_id'    => $transaction->id,
                    'amount_cents'      => $amountCents,
                    'outstanding_cents' => $outstandingCents,
                    'excess_cents'      => $amountCents - $outstandingCents,
                ]);
                $amountCents = $outstandingCents;
            }

            $allocation = $this->allocatePaymentAcrossTerms($term, $amountCents);

            foreach ($allocation as $alloc) {
                if ($user->student) {
                    $paymentOrNumber = $transaction->or_number
                        ?? ($transaction->meta['reference_number'] ?? null);

                    Payment::create([
                        'student_id'            => $user->student->id,
                        'student_assessment_id' => $term->student_assessment_id,
                        'amount'                => $alloc['applied_decimal'],
                        'or_number'             => $paymentOrNumber,
                        'payment_method'        => $transaction->payment_channel,
                        'description'           => 'Payment — ' . $alloc['term_name'],
                        'status'                => PaymentStatus::COMPLETED->value,
                        'created_at'            => $transaction->created_at ?? now(),
                        'updated_at'            => $transaction->created_at ?? now(),
                    ]);
                }
            }

            $totalAppliedCents = MoneyService::sum(array_column($allocation, 'applied_cents'));
            $termsLabel        = collect($allocation)->pluck('term_name')->implode(' + ');
            $termsCount        = count($allocation);

            $description = $termsCount > 1
                ? MoneyService::formatFromCents($totalAppliedCents) . " allocated across: {$termsLabel}"
                : 'Payment — ' . ($allocation[0]['term_name'] ?? $term->term_name);

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
                    'total_applied'  => MoneyService::toPesos($totalAppliedCents),
                    'finalized_at'   => now()->toIso8601String(),
                    'description'    => $description,
                ]),
            ]);

            AccountService::recalculate($user);
            $this->checkAndNotifyProgressionReady($user, $term->student_assessment_id);

            Log::info('Payment finalized with allocation', [
                'transaction_id'   => $transaction->id,
                'starting_term'    => $term->term_name,
                'amount_cents'     => $amountCents,
                'terms_covered'    => $termsCount,
                'total_applied'    => MoneyService::toPesos($totalAppliedCents),
                'allocation_count' => count($allocation),
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
     * Get the total outstanding balance for a user in pesos (float).
     */
    public function getTotalOutstandingBalance(User $user): float
    {
        // Filter by balance > 0 (not by status) — balance is authoritative.
        $cents = MoneyService::sumFromDb(
            StudentPaymentTerm::whereHas('assessment', function ($q) use ($user) {
                $q->where('user_id', $user->id);
            })
            ->where('balance', '>', 0)
            ->sum('balance')
        );

        return MoneyService::toFloat($cents);
    }

    /**
     * Public proxy for checkAndNotifyProgressionReady.
     */
    public function notifyProgressionIfComplete(User $user, int $assessmentId): void
    {
        $this->checkAndNotifyProgressionReady($user, $assessmentId);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PRIVATE: Integer-Cents Sequential Allocation Engine
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Allocate a payment amount starting at $startTerm, then flowing into
     * subsequent terms ordered by term_order ASC.
     *
     * ── STEP 1: Sequential allocation loop ──────────────────────────────────
     * Apply payment to $startTerm first. If payment exceeds the term balance,
     * the excess continues to the next unpaid term, and so on.
     *
     * ── STEP 2: Close-and-carry (ONE-TIME TERM PROCESSING RULE) ─────────────
     * After the loop, any term that received a partial payment (balance > 0 remains)
     * is evaluated:
     *
     *   IF a next term exists with balance > 0:
     *     → Carry the remaining balance to that next term.
     *     → Close THIS term: balance = 0, status = 'processed'.
     *     → SUM(all term balances) is unchanged — money moved, not destroyed.
     *
     *   IF NO next term exists (this IS the final term):
     *     → DO NOT close. DO NOT zero the balance.
     *     → Set status = 'underpaid'.
     *     → The remaining balance stays on this term. The student must pay
     *       it in a future transaction.
     *     → SUM(all term balances) is unchanged — nothing moved, nothing lost.
     *
     * INVARIANT: SUM(all term balances) is identical before and after Step 2.
     * AccountService::recalculate() will always see the correct total.
     *
     * ALL ARITHMETIC IS INTEGER-CENTS. Zero float error possible.
     *
     * @param  StudentPaymentTerm  $startTerm    First term to apply payment to.
     * @param  int                 $amountCents  Payment amount in integer cents.
     * @return array               Allocation ledger, one entry per affected term.
     */
    private function allocatePaymentAcrossTerms(StudentPaymentTerm $startTerm, int $amountCents): array
    {
        $allocation     = [];
        $remainingCents = $amountCents;

        // ── STEP 1: Sequential allocation loop ──────────────────────────────
        //
        // Terms eligible for payment:
        //   - The selected starting term (by ID), even if term_order puts it
        //     behind others (admin override path).
        //   - All terms with term_order > startTerm.term_order and balance > 0.
        //
        // We explicitly EXCLUDE processed terms (balance = 0 via carryover).
        // The balance > 0 filter handles this automatically since processed
        // terms always have balance = 0 after Step 2.

        $terms = StudentPaymentTerm::where('student_assessment_id', $startTerm->student_assessment_id)
            ->where('balance', '>', 0)  // authoritative filter — status can be stale
            ->where(function ($q) use ($startTerm) {
                $q->where('id', $startTerm->id)
                  ->orWhere('term_order', '>', $startTerm->term_order);
            })
            ->orderBy('term_order', 'asc')
            ->lockForUpdate()
            ->get();

        foreach ($terms as $term) {
            if ($remainingCents <= 0) {
                break;
            }

            // Integer cents — no float arithmetic anywhere in this block.
            $balanceBeforeCents = MoneyService::toCents($term->balance);
            $appliedCents       = min($remainingCents, $balanceBeforeCents);
            $balanceAfterCents  = $balanceBeforeCents - $appliedCents; // exact integer subtraction

            // Determine status after Step 1.
            // PARTIAL here is a temporary internal marker meaning "balance remains
            // on this term after payment." Step 2 will resolve PARTIAL to either:
            //   → PROCESSED (if a next term exists, carry the balance forward), or
            //   → UNDERPAID  (if this is the final term, balance stays here).
            $statusAfterStep1 = $balanceAfterCents === 0
                ? PaymentStatus::PAID->value
                : PaymentStatus::PARTIAL->value;

            $term->update([
                'balance'   => MoneyService::toPesos($balanceAfterCents),
                'status'    => $statusAfterStep1,
                'paid_date' => $statusAfterStep1 === PaymentStatus::PAID->value ? now() : $term->paid_date,
            ]);

            $allocation[] = [
                'term_id'               => $term->id,
                'term_name'             => $term->term_name,
                'term_order'            => $term->term_order,
                'applied_cents'         => $appliedCents,
                'applied_decimal'       => MoneyService::toPesos($appliedCents),
                'balance_before_cents'  => $balanceBeforeCents,
                'balance_after_cents'   => $balanceAfterCents,
                'balance_before'        => MoneyService::toPesos($balanceBeforeCents),
                'balance_after'         => MoneyService::toPesos($balanceAfterCents),
                // Legacy float aliases for callers that still read these keys.
                'applied'               => MoneyService::toFloat($appliedCents),
                'balance_before_float'  => MoneyService::toFloat($balanceBeforeCents),
                'balance_after_float'   => MoneyService::toFloat($balanceAfterCents),
                // Step 2 will populate these fields for PARTIAL terms.
                'status_after'          => $statusAfterStep1,
                'carried_forward_cents' => 0,
                'carried_to_term_name'  => null,
            ];

            $remainingCents -= $appliedCents; // exact integer subtraction
        }

        // ── STEP 2: Close-and-carry (ONE-TIME TERM PROCESSING RULE) ─────────
        //
        // For each allocation entry that ended Step 1 with PARTIAL status,
        // determine whether this is a mid-term (carry forward) or the final
        // term (leave as UNDERPAID).
        //
        // Processing order: we iterate allocation entries in term_order ASC,
        // so chain carries work correctly:
        //   Prelim → PARTIAL → next term exists → PROCESSED + carry to Midterm
        //   Midterm → PARTIAL → next term exists → PROCESSED + carry to Semi-Final
        // Multiple terms can be chain-carried in a single payment.
        //
        // After Step 2, the allocation entry is updated to reflect the final
        // status, so receipt PDFs and audit logs have the complete picture.

        foreach ($allocation as &$alloc) {
            // Only process entries that ended Step 1 with remaining balance.
            if ($alloc['status_after'] !== PaymentStatus::PARTIAL->value) {
                continue;
            }

            $carryoverCents = $alloc['balance_after_cents'];

            if ($carryoverCents <= 0) {
                // Defensive guard — should be unreachable given the filter above.
                Log::warning('allocatePaymentAcrossTerms: PARTIAL entry has zero carry', [
                    'term_id'   => $alloc['term_id'],
                    'term_name' => $alloc['term_name'],
                ]);
                continue;
            }

            // Find the next term AFTER this one that has (or will have) a balance.
            // We must look beyond the Step 1 scope — the next term may not have
            // been in the Step 1 query (e.g., Midterm was not reached because
            // the payment ran out on Prelim).
            $nextTerm = StudentPaymentTerm::where('student_assessment_id', $startTerm->student_assessment_id)
                ->where('term_order', '>', $alloc['term_order'])
                ->where(function ($q) {
                    // The next term either:
                    //   (a) already has balance > 0 (not yet reached by payment), OR
                    //   (b) had balance carried into it earlier in this same Step 2 loop.
                    // We intentionally DO NOT carry into a PAID term (fully settled).
                    // We also do not carry into a PROCESSED term (they have balance = 0).
                    $q->where('balance', '>', 0);
                })
                ->orderBy('term_order', 'asc')
                ->lockForUpdate()
                ->first();

            if ($nextTerm) {
                // ── MID-TERM PATH: carry forward and close this term ──────────
                //
                // A next term exists. Apply the carry-forward rule:
                //   1. Add the carry amount to the next term's balance.
                //   2. Zero this term's balance and mark it PROCESSED.
                //
                // BALANCE INVARIANT: we add $carryoverCents to the next term
                // and subtract it from this term (→ 0). Net change = 0.
                // AccountService::recalculate() sees the same total outstanding.

                $nextBalanceBefore = MoneyService::toCents($nextTerm->balance);
                $nextBalanceAfter  = $nextBalanceBefore + $carryoverCents;

                $nextTerm->update([
                    'balance'                => MoneyService::toPesos($nextBalanceAfter),
                    'remarks'                => 'Carry-over of ' . MoneyService::formatFromCents($carryoverCents)
                                               . ' from ' . $alloc['term_name'],
                    // status stays as-is (pending/unpaid) — the term still owes money
                    'carryover_from_term_id' => $alloc['term_id'],
                    'carryover_amount'       => MoneyService::toPesos($carryoverCents),
                ]);

                $closedTerm = StudentPaymentTerm::lockForUpdate()->find($alloc['term_id']);
                if ($closedTerm) {
                    $closedTerm->update([
                        'balance'   => '0.00',
                        'status'    => PaymentStatus::PROCESSED->value,
                        'paid_date' => null,  // not fully paid — do not stamp paid_date
                        'remarks'   => MoneyService::formatFromCents($carryoverCents)
                                       . ' carried to ' . $nextTerm->term_name,
                    ]);
                }

                $alloc['status_after']          = PaymentStatus::PROCESSED->value;
                $alloc['balance_after_cents']   = 0;
                $alloc['balance_after']         = '0.00';
                $alloc['balance_after_float']   = 0.0;
                $alloc['carried_forward_cents'] = $carryoverCents;
                $alloc['carried_to_term_name']  = $nextTerm->term_name;

                Log::info('allocatePaymentAcrossTerms: carry-forward applied', [
                    'from_term'          => $alloc['term_name'],
                    'to_term'            => $nextTerm->term_name,
                    'carryover_cents'    => $carryoverCents,
                    'next_balance_after' => MoneyService::toPesos($nextBalanceAfter),
                ]);

            } else {
                // ── FINAL-TERM PATH: leave balance here, set UNDERPAID ────────
                //
                // No next term exists — this IS the last term in the assessment.
                // The one-time processing rule does NOT apply here.
                //
                // DO NOT zero the balance. DO NOT set status = 'processed'.
                // The student still owes $carryoverCents on this term.
                // Set status = 'underpaid' so the UI, queries, and audit logs
                // can clearly distinguish this from a closed/carried mid-term.
                //
                // BALANCE INVARIANT: nothing moves. The $carryoverCents remain
                // exactly where they are. AccountService::recalculate() will
                // correctly sum this term's balance as outstanding.

                $underpaidTerm = StudentPaymentTerm::lockForUpdate()->find($alloc['term_id']);
                if ($underpaidTerm) {
                    $underpaidTerm->update([
                        // balance stays as-is — DO NOT write '0.00'
                        'status'  => PaymentStatus::UNDERPAID->value,
                        'remarks' => 'Partial payment received. Remaining '
                                     . MoneyService::formatFromCents($carryoverCents)
                                     . ' is due — this is the final payment term.',
                    ]);
                }

                // Update the ledger entry — status is now UNDERPAID, balance retained.
                // balance_after_cents / balance_after / balance_after_float remain
                // as their Step 1 values ($carryoverCents) — do NOT zero them.
                $alloc['status_after']          = PaymentStatus::UNDERPAID->value;
                $alloc['carried_forward_cents'] = 0;   // nothing was carried
                $alloc['carried_to_term_name']  = null;

                Log::info('allocatePaymentAcrossTerms: final-term underpaid — balance retained', [
                    'term_id'        => $alloc['term_id'],
                    'term_name'      => $alloc['term_name'],
                    'remaining_cents'=> $carryoverCents,
                    'assessment_id'  => $startTerm->student_assessment_id,
                    'note'           => 'This is the last term. No carry-forward. Student must pay the remainder.',
                ]);
            }
        }
        unset($alloc); // break the reference to avoid accidental mutation

        if ($remainingCents > 1) {
            Log::error('allocatePaymentAcrossTerms: unallocated remainder after exhausting all terms', [
                'start_term_id'   => $startTerm->id,
                'amount_cents'    => $amountCents,
                'remaining_cents' => $remainingCents,
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

            // Trust balance, not status. status can be stale; balance is authoritative.
            // An UNDERPAID term has balance > 0, so this check correctly returns false
            // when the final term still has an outstanding amount.
            $allPaid = $assessment->paymentTerms->isNotEmpty()
                && $assessment->paymentTerms->every(
                    fn ($t) => (float) $t->balance === 0.0
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
            '1st Year|1st' => '1st Year 2nd Semester',
            '1st Year|2nd' => '2nd Year 1st Semester',
            '2nd Year|1st' => '2nd Year 2nd Semester',
            '2nd Year|2nd' => '3rd Year 1st Semester',
            '3rd Year|1st' => '3rd Year 2nd Semester',
            '3rd Year|2nd' => '4th Year 1st Semester',
            '4th Year|1st' => '4th Year 2nd Semester',
            '4th Year|2nd' => 'graduation (program completed)',
        ];

        return $progression["{$yearLevel}|{$semester}"] ?? 'next semester';
    }
}