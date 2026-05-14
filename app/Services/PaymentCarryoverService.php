<?php

namespace App\Services;

use App\Models\StudentPaymentTerm;
use App\Models\StudentAssessment;
use App\Services\MoneyService;
use Illuminate\Support\Facades\DB;

class PaymentCarryoverService
{
    /**
     * Apply carryover logic to all payment terms for an assessment.
     *
     * Each term's balance = its own original amount PLUS any unpaid balance
     * carried forward from the previous term.
     *
     * All arithmetic is performed in integer cents via MoneyService.
     * Zero float-arithmetic occurs in this method.
     */
    public function applyCarryoverToAssessment(StudentAssessment $assessment): void
    {
        DB::transaction(function () use ($assessment) {
            $terms = $assessment->paymentTerms()
                ->orderBy('term_order')
                ->get();

            if ($terms->count() === 0) {
                return;
            }

            // Integer cents — no float accumulation.
            $carryoverCents = 0;

            foreach ($terms as $term) {
                $previousUnpaidCents  = $carryoverCents;
                $currentAmountCents   = MoneyService::toCents($term->amount);

                // Exact integer addition — no rounding drift.
                $totalCents = $previousUnpaidCents + $currentAmountCents;

                $remarks = null;
                if ($previousUnpaidCents > 0) {
                    $remarks = 'Balance of ' . MoneyService::formatFromCents($previousUnpaidCents) . ' carried from previous term(s)';
                }

                $term->update([
                    'balance' => MoneyService::toPesos($totalCents),
                    'remarks' => $remarks,
                    'status'  => $totalCents > 0 ? StudentPaymentTerm::STATUS_PENDING : StudentPaymentTerm::STATUS_PAID,
                ]);

                // Use the locally-computed $totalCents — NOT $term->balance (stale after update()).
                $carryoverCents = $totalCents;
            }

            // Annotate the last term so it is clear no further carryover occurs.
            $lastTerm = $terms->last();
            if ($lastTerm && MoneyService::toCents($lastTerm->balance) > 0) {
                $existing = $lastTerm->remarks ?? '';
                $lastTerm->update([
                    'remarks' => ($existing ? $existing . '. ' : '') . 'Final term — no carryover beyond this',
                ]);
            }
        });
    }

    /**
     * Apply a payment across terms using carryover priority.
     *
     * Payments are distributed to the earliest unpaid terms first (term_order ASC).
     *
     * FIXED: The previous implementation lacked round() on $remainingAmount -= $amountToApply,
     * causing float residue to persist across iterations (e.g. 5000 - 4078.20 = 921.799999...).
     * Now uses integer cents throughout — no rounding at any step.
     */
    public function applyPayment(StudentAssessment $assessment, float|int|string $paymentAmount): array
    {
        return DB::transaction(function () use ($assessment, $paymentAmount) {
            $appliedPayments  = [];
            $remainingCents   = MoneyService::roundToCents($paymentAmount);
            $totalAmountCents = $remainingCents;

            $terms = $assessment->paymentTerms()
                ->where('balance', '>', 0)
                ->orderBy('term_order')
                ->get();

            foreach ($terms as $term) {
                if ($remainingCents <= 0) {
                    break;
                }

                $termBalanceCents   = MoneyService::toCents($term->balance);
                $amountToApplyCents = min($remainingCents, $termBalanceCents);
                $newBalanceCents    = $termBalanceCents - $amountToApplyCents; // exact integer subtraction

                $newStatus = $newBalanceCents === 0
                    ? StudentPaymentTerm::STATUS_PAID
                    : StudentPaymentTerm::STATUS_PARTIAL;

                $term->update([
                    'balance'   => MoneyService::toPesos($newBalanceCents),
                    'status'    => $newStatus,
                    'paid_date' => $newStatus === StudentPaymentTerm::STATUS_PAID ? now() : $term->paid_date,
                ]);

                $appliedPayments[] = [
                    'term'              => $term->term_name,
                    'applied'           => MoneyService::toFloat($amountToApplyCents),
                    'applied_decimal'   => MoneyService::toPesos($amountToApplyCents),
                    'remaining_balance' => MoneyService::toFloat($newBalanceCents),
                ];

                $remainingCents -= $amountToApplyCents; // exact integer subtraction
            }

            $totalAppliedCents = $totalAmountCents - $remainingCents;

            return [
                'applied_payments'       => $appliedPayments,
                'remaining_amount'       => MoneyService::toFloat($remainingCents),
                'remaining_amount_cents' => $remainingCents,
                'total_applied'          => MoneyService::toFloat($totalAppliedCents),
                'total_applied_cents'    => $totalAppliedCents,
            ];
        });
    }

    /**
     * Get total remaining balance across all terms for an assessment.
     */
    public function getTotalRemainingBalance(StudentAssessment $assessment): float
    {
        $cents = MoneyService::sumFromDb($assessment->paymentTerms()->sum('balance'));
        return MoneyService::toFloat($cents);
    }

    /**
     * Check if assessment is fully paid.
     */
    public function isFullyPaid(StudentAssessment $assessment): bool
    {
        return MoneyService::sumFromDb($assessment->paymentTerms()->sum('balance')) === 0;
    }

    /**
     * Get the next unpaid/partially-paid/overdue term for an assessment.
     */
    public function getNextPendingTerm(StudentAssessment $assessment): ?StudentPaymentTerm
    {
        return $assessment->paymentTerms()
            ->whereIn('status', \App\Enums\PaymentStatus::unpaidValues())
            ->orderBy('term_order')
            ->first();
    }

    /**
     * Get a full payment breakdown suitable for display or API response.
     */
    public function getPaymentBreakdown(StudentAssessment $assessment): array
    {
        $terms = $assessment->paymentTerms()
            ->orderBy('term_order')
            ->get()
            ->map(fn ($term) => [
                'id'              => $term->id,
                'term_name'       => $term->term_name,
                'term_order'      => $term->term_order,
                'percentage'      => $term->percentage,
                'original_amount' => MoneyService::toFloat(MoneyService::toCents($term->amount)),
                'balance'         => MoneyService::toFloat(MoneyService::toCents($term->balance)),
                'status'          => $term->status,
                'due_date'        => $term->due_date?->format('Y-m-d'),
                'remarks'         => $term->remarks,
                'has_carryover'   => $term->hasCarryover(),
            ])
            ->toArray();

        $totalRemainingCents  = MoneyService::sumFromDb($assessment->paymentTerms()->sum('balance'));
        $totalAssessmentCents = MoneyService::toCents($assessment->total_assessment);
        $totalPaidCents       = $totalAssessmentCents - $totalRemainingCents;

        return [
            'total_assessment' => MoneyService::toFloat($totalAssessmentCents),
            'total_paid'       => MoneyService::toFloat($totalPaidCents),
            'total_remaining'  => MoneyService::toFloat($totalRemainingCents),
            'is_fully_paid'    => $totalRemainingCents === 0,
            'terms'            => $terms,
        ];
    }
}