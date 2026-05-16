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
     *   3. Payment MUST NOT exceed total outstanding balance across all terms.
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
        // status can be stale (e.g. paid status with remaining balance from old bugs).
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

            $newStatus = $balanceAfterCents === 0
                ? PaymentStatus::PAID->value
                : PaymentStatus::PARTIAL->value;

            $term->update([
                'balance'   => MoneyService::toPesos($balanceAfterCents),
                'status'    => $newStatus,
                'paid_date' => $newStatus === PaymentStatus::PAID->value ? now() : $term->paid_date,
            ]);

            $allocation[] = [
                'term_id'              => $term->id,
                'term_name'            => $term->term_name,
                'term_order'           => $term->term_order,
                'applied_cents'        => $appliedCents,
                'applied_decimal'      => MoneyService::toPesos($appliedCents),
                'balance_before_cents' => $balanceBeforeCents,
                'balance_after_cents'  => $balanceAfterCents,
                'balance_before'       => MoneyService::toPesos($balanceBeforeCents),
                'balance_after'        => MoneyService::toPesos($balanceAfterCents),
                // Legacy float aliases for any callers that still use them.
                'applied'              => MoneyService::toFloat($appliedCents),
                'balance_before_float' => MoneyService::toFloat($balanceBeforeCents),
                'balance_after_float'  => MoneyService::toFloat($balanceAfterCents),
                'status_after'         => $newStatus,
            ];

            $remainingCents -= $appliedCents; // exact integer subtraction
        }

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
        // Keys use the short form stored in student_assessments.semester: '1st', '2nd'
        // NOT the legacy '1st Sem', '2nd Sem' format.
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