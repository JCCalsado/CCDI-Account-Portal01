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
 * 'unpaid'    → legacy initial value (pre-normalisation migration). Treated as
 *               fully unpaid — equivalent to 'pending' for all balance queries.
 * 'pending'   → canonical initial value; term not yet paid.
 * 'partial'   → LEGACY: partial payment applied but balance > 0 remains.
 *               Only appears on mid-terms before the carry rule ran. New code
 *               does NOT produce this status — it produces 'processed' for
 *               mid-terms and 'underpaid' for the final term.
 * 'underpaid' → the FINAL term in an assessment received a partial payment.
 *               The remaining balance stays on this term — there is no next
 *               term to carry to. Student must pay the remainder in a future
 *               transaction. balance > 0 always.
 * 'paid'      → balance = 0, fully settled by an exact or excess payment.
 * 'processed' → balance = 0, a partial payment was applied and the remaining
 *               balance was carried forward to the next term. Term is closed.
 *               ONE-TIME TERM PROCESSING RULE: once processed, never re-opened.
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
     * LEGACY: partial payment applied, balance > 0 remains on this mid-term.
     * Pre-dates the carry-forward rule. New code produces PROCESSED for
     * mid-terms and UNDERPAID for the final term.
     *
     * @deprecated New allocations produce PROCESSED (mid-term) or UNDERPAID (final term).
     *             This value is retained for backward compatibility with existing rows.
     */
    case PARTIAL = 'partial';

    /**
     * The final term in an assessment received a partial payment.
     * Remaining balance stays on this term — no next term to carry to.
     * Student must pay the remainder in a future transaction.
     * balance > 0 always. Term is NOT closed.
     */
    case UNDERPAID = 'underpaid';

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
            self::UNDERPAID         => 'Underpaid',
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
            // UNDERPAID = amber — final term with outstanding balance; distinct
            // from orange (legacy partial) and red (error states).
            self::UNDERPAID                    => 'text-amber-700 bg-amber-50',
            // PROCESSED = blue — closed & carried forward; not a failure, not a success.
            self::PROCESSED                    => 'text-blue-700 bg-blue-50',
        };
    }

    /**
     * Returns status values that represent "still owes money" for StudentPaymentTerm.
     *
     * ✅ INCLUDES 'unpaid': legacy initial value; treated as fully unpaid.
     * ✅ INCLUDES 'partial': legacy mid-term rows with balance > 0.
     * ✅ INCLUDES 'underpaid': final term with remaining balance after partial payment.
     *
     * ❌ EXCLUDES 'processed': processed terms have balance = 0 (closed, carried).
     * ❌ EXCLUDES 'paid': fully settled, balance = 0.
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
            'unpaid',                  // legacy initial status
            self::PENDING->value,      // canonical initial status
            self::PARTIAL->value,      // legacy mid-term partial
            self::UNDERPAID->value,    // final term with remaining balance
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