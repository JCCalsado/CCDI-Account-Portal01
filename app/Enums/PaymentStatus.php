<?php

namespace App\Enums;

/**
 * PaymentStatus — single source of truth for all payment/transaction statuses.
 *
 * USAGE
 * -----
 * Write:   PaymentStatus::PAID->value          → 'paid'
 * Check:   $model->status === PaymentStatus::PAID->value
 * In:      whereIn('status', PaymentStatus::unpaidValues())
 *
 * STATUS VOCABULARY (student_payment_terms.status)
 * ------------------------------------------------
 * 'unpaid'    → term created, no payment ever attempted (legacy/initial value
 *               produced by AssessmentService::buildPaymentTerms). Treated as
 *               fully unpaid — equivalent to 'pending' for all balance queries.
 * 'pending'   → same as above; the normalised form going forward.
 * 'partial'   → LEGACY: payment applied but balance > 0 remains on this term
 *               (only appears on the final active term in a payment chain).
 *               New code produces 'processed' instead for intermediate terms.
 * 'paid'      → balance = 0, fully settled by an exact or excess payment.
 * 'processed' → balance = 0, a partial payment was applied and the remaining
 *               balance was carried forward to the next term. Term is closed.
 *               This is the one-time term processing rule in action.
 */
enum PaymentStatus: string
{
    // ── Transaction / StudentPaymentTerm statuses ─────────────────────────────

    /** Bank transfer submitted — waiting for student to upload proof. */
    case AWAITING_PROOF = 'awaiting_proof';

    /** Payment submitted and fully confirmed. Term balance has been deducted. */
    case PAID = 'paid';

    /** Charge created or term not yet paid. No payment received. */
    case PENDING = 'pending';

    /** Student submitted a payment; waiting for accounting to approve. */
    case AWAITING_APPROVAL = 'awaiting_approval';

    /** Payment was rejected by accounting and will not be applied. */
    case CANCELLED = 'cancelled';

    /**
     * Legacy: partial payment applied, balance > 0 remains on this specific term.
     * In new flows, intermediate terms become PROCESSED and only the final
     * active term in a chain uses PARTIAL.
     */
    case PARTIAL = 'partial';

    /** Payment gateway returned an error and payment was not processed. */
    case FAILED = 'failed';

    // ── Payment model status (maps to Payment::STATUS_COMPLETED) ─────────────

    /** Payment record has been created and reconciled (used in payments table). */
    case COMPLETED = 'completed';

    /**
     * Term received a partial payment; remaining balance carried forward to
     * the next term. The term balance is now ₱0.00 and this term is closed.
     *
     * ONE-TIME TERM PROCESSING RULE: once a term is PROCESSED it never
     * re-appears as the payable term. All subsequent payments target the
     * next term in sequence.
     */
    case PROCESSED = 'processed';

    // =========================================================================
    // HELPERS
    // =========================================================================

    /**
     * Human-readable label for display in the UI.
     */
    public function label(): string
    {
        return match ($this) {
            self::PAID              => 'Paid',
            self::PENDING           => 'Pending',
            self::AWAITING_APPROVAL => 'Awaiting Approval',
            self::CANCELLED         => 'Cancelled',
            self::PARTIAL           => 'Partial',
            self::FAILED            => 'Failed',
            self::COMPLETED         => 'Completed',
            self::AWAITING_PROOF    => 'Awaiting Proof',
            self::PROCESSED         => 'Processed',
        };
    }

    /**
     * Tailwind CSS color class hint for badge rendering in Vue components.
     */
    public function colorClass(): string
    {
        return match ($this) {
            self::PAID, self::COMPLETED        => 'text-green-600 bg-green-50',
            self::PENDING                      => 'text-yellow-600 bg-yellow-50',
            self::AWAITING_APPROVAL            => 'text-blue-600 bg-blue-50',
            self::AWAITING_PROOF               => 'text-purple-600 bg-purple-50',
            self::CANCELLED, self::FAILED      => 'text-red-600 bg-red-50',
            self::PARTIAL                      => 'text-orange-600 bg-orange-50',
            // PROCESSED = blue — closed & carried forward; not a failure, not a success
            self::PROCESSED                    => 'text-blue-700 bg-blue-50',
        };
    }

    /**
     * Returns status values that represent "still owes money" for StudentPaymentTerm.
     *
     * ✅ INCLUDES 'unpaid': AssessmentService::buildPaymentTerms() sets status='unpaid'
     * for newly created terms. This is the legacy initial value. All balance queries
     * MUST include 'unpaid' or they will return ₱0 for fresh assessments.
     *
     * ✅ INCLUDES 'partial': legacy terms where balance > 0 remains.
     *
     * ❌ EXCLUDES 'processed': processed terms have balance = 0.
     *    They are closed. Querying for them would return no balance.
     *
     * ⚠️  NOTE: Do not use this to filter term balance queries. Use
     *    ->where('balance', '>', 0) instead — balance is the authoritative
     *    field. Status can be stale. This method is for display/UI logic only.
     *
     * @return string[]
     */
    public static function unpaidValues(): array
    {
        return [
            'unpaid',                // initial status from AssessmentService::buildPaymentTerms()
            self::PENDING->value,
            self::PARTIAL->value,
            // PROCESSED is NOT here — processed terms have balance = 0
        ];
    }

    /**
     * Returns all raw string values (useful for validation rule `in:` lists).
     *
     * @return string[]
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}