/**
 * useMoney — Integer-Cents Monetary Arithmetic for Vue 3
 *
 * PURPOSE
 * ───────
 * Eliminates floating-point precision errors in all monetary calculations
 * on the frontend. Mirrors the PHP MoneyService contract exactly.
 *
 * JavaScript uses IEEE 754 double-precision floats for all numbers, which
 * cannot represent most decimal fractions exactly:
 *
 *   5000.00 - 4078.20  ===  921.7999999999997  (float subtraction)
 *   (921.8).toFixed(2) ===  "921.80"           (appears fixed, but...)
 *   parseFloat("921.80") - 0  ===  921.8000000001  (float again!)
 *
 * This composable converts all amounts to integer cents at the boundary,
 * performs all arithmetic in integers, and only converts back to decimal
 * for display or submission.
 *
 * RULES
 * ─────
 *   ✅  DO call toCents() on every amount coming from props or user input.
 *   ✅  DO perform ALL arithmetic on integer cents.
 *   ✅  DO call fromCents() only for display or final form submission.
 *   ❌  NEVER use parseFloat(), toFixed(), or Number() for business logic.
 *   ❌  NEVER chain toFixed() + parseFloat() — this re-introduces float error.
 *   ❌  NEVER use raw `amount - other` without cent conversion.
 *
 * EXAMPLES
 * ────────
 *   // OLD (broken — 5000 may become 4999.99)
 *   let remaining = parseFloat(form.amount.toFixed(2));
 *   remaining -= parseFloat(term.balance.toFixed(2));
 *
 *   // NEW (exact — always 5000.00)
 *   let remainingCents = toCents(form.amount);
 *   remainingCents -= toCents(term.balance);
 *   const display = formatCurrency(fromCents(remainingCents));
 */

// ─────────────────────────────────────────────────────────────────────────────
// INPUT BOUNDARY: Convert to Integer Cents
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Convert a peso amount to integer cents.
 *
 * Uses Math.round() to handle sub-cent float noise at the input boundary.
 * After this call, all arithmetic is exact integer math.
 *
 * Examples:
 *   toCents(5000)       === 500000
 *   toCents(4078.20)    === 407820
 *   toCents(921.80)     === 92180
 *   toCents(0.01)       === 1
 *   toCents(undefined)  === 0
 */
export function toCents(amount: number | string | null | undefined): number {
    if (amount === null || amount === undefined || amount === '') return 0;

    const num = typeof amount === 'string'
        ? parseFloat(amount.replace(/[^0-9.\-]/g, ''))
        : amount;

    if (isNaN(num)) return 0;

    // Math.round eliminates sub-cent float noise (e.g. 921.7999999999997 → 92180)
    return Math.round(num * 100);
}

// ─────────────────────────────────────────────────────────────────────────────
// OUTPUT BOUNDARY: Convert from Integer Cents
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Convert integer cents back to a peso float value.
 *
 * ⚠️  USE ONLY FOR DISPLAY OR FORM SUBMISSION. Never pipe back into arithmetic.
 *
 * The result is a JavaScript number (float) for use with Intl.NumberFormat.
 *
 * Examples:
 *   fromCents(500000) === 5000.00
 *   fromCents(92180)  === 921.80
 *   fromCents(1)      === 0.01
 */
export function fromCents(cents: number): number {
    return cents / 100;
}

// ─────────────────────────────────────────────────────────────────────────────
// PAYMENT ALLOCATION
// ─────────────────────────────────────────────────────────────────────────────

/**
 * One entry in the payment allocation result.
 *
 * Step 1 fields (always populated):
 *   - term_id, term_name, term_order, balance_before, applied, applied_cents
 *   - balance_after: remaining balance after Step 1 application
 *   - fully_paid: true when the term was exactly or over-paid
 *
 * Step 2 fields (populated for terms that trigger the close-and-carry rule):
 *   - processed:       true = this term is closed by the carry rule (balance → 0)
 *   - carried_forward: peso amount moved to the next term (display only)
 *   - carried_to_term: name of the receiving term (null for the final term)
 *
 * NOTE: `processed` is distinct from `fully_paid`:
 *   - fully_paid  = term balance was exactly covered by this payment
 *   - processed   = term had remaining balance that was carried forward to the
 *                   next term (one-time term processing rule). balance_after = 0
 *                   but the student has NOT paid this term in full.
 */
export interface AllocationEntry {
    term_id:         number;
    term_name:       string;
    term_order:      number;
    balance_before:  number;   // in pesos (for display)
    applied:         number;   // in pesos (for display)
    balance_after:   number;   // in pesos (for display — 0 when fully_paid OR processed)
    applied_cents:   number;   // raw cents (for calculations)
    fully_paid:      boolean;  // true when term is exactly settled by this payment
    // ── Step 2: Close-and-carry fields ────────────────────────────────────────
    // Populated only when a partial payment triggers the one-time processing rule.
    // A term is NOT processed if it is the last term in the assessment (no next
    // term to receive the carry). That case stays as PARTIAL on the server.
    processed:       boolean;       // true = term closed; remaining balance carried forward
    carried_forward: number;        // pesos moved to next term (display only)
    carried_to_term: string | null; // name of the receiving term
}

export interface AllocatableTerm {
    id:         number;
    term_name:  string;
    term_order: number;
    balance:    number;  // in pesos
}

/**
 * Allocate a payment amount across an ordered list of unpaid terms.
 *
 * Mirrors PHP StudentPaymentService::allocatePaymentAcrossTerms() exactly,
 * including the Step 2 close-and-carry rule. Used for the payment preview
 * in Create.vue (accounting) and the allocation breakdown in Approvals.
 *
 * STEP 1: Sequential allocation
 *   Distribute the payment amount across terms in term_order sequence.
 *   Each term receives as much as its balance allows, up to the remaining
 *   payment amount.
 *
 * STEP 2: Close-and-carry (ONE-TIME TERM PROCESSING RULE)
 *   For any term that ended Step 1 with balance remaining (partial payment):
 *     a. Find the next term in the ORIGINAL list with balance > 0.
 *     b. Close this term (set balance_after = 0, mark processed = true).
 *     c. Record carry metadata for the UI (carried_forward, carried_to_term).
 *   Exception: if this is the LAST term (highest term_order), do NOT apply
 *   the carry rule. Leave it as partial — the student pays the remainder
 *   in a future transaction.
 *
 * @param amountCents  Total payment in integer cents.
 * @param terms        Array of { id, term_name, term_order, balance } — all
 *                     outstanding terms for the assessment, not just the ones
 *                     in the payment's scope. The full list is required for
 *                     Step 2 to correctly identify the last term.
 */
export function allocatePayment(
    amountCents: number,
    terms: AllocatableTerm[],
): AllocationEntry[] {
    const result: AllocationEntry[] = [];
    let remainingCents = amountCents;

    const sorted = [...terms].sort((a, b) => a.term_order - b.term_order);

    // ── Step 1: Sequential allocation ────────────────────────────────────────
    for (const term of sorted) {
        if (remainingCents <= 0) break;

        const balanceBeforeCents = toCents(term.balance);
        const appliedCents       = Math.min(remainingCents, balanceBeforeCents);
        const balanceAfterCents  = balanceBeforeCents - appliedCents;

        result.push({
            term_id:         term.id,
            term_name:       term.term_name,
            term_order:      term.term_order,
            balance_before:  fromCents(balanceBeforeCents),
            applied:         fromCents(appliedCents),
            balance_after:   fromCents(balanceAfterCents),
            applied_cents:   appliedCents,
            fully_paid:      balanceAfterCents === 0,
            // Step 2 fields — populated below
            processed:       false,
            carried_forward: 0,
            carried_to_term: null,
        });

        remainingCents -= appliedCents;
    }

    // ── Step 2: Close-and-carry (ONE-TIME TERM PROCESSING RULE) ──────────────
    // Mirrors PHP allocatePaymentAcrossTerms() Step 2 exactly.
    //
    // LAST-TERM EXCEPTION:
    //   The term with the highest term_order across ALL terms in the assessment
    //   (not just Step 1 results) is NEVER closed and carried. This matches
    //   the backend's maxTermOrder guard. The student must pay the remainder
    //   in a future payment.
    //
    //   We derive maxTermOrder from the full `sorted` array, not from `result`
    //   (which only contains terms that received money in Step 1).
    const maxTermOrder = sorted.length > 0
        ? Math.max(...sorted.map((t) => t.term_order))
        : 0;

    for (let i = 0; i < result.length; i++) {
        const entry = result[i];

        // Only process entries with remaining balance after Step 1.
        if (entry.fully_paid || entry.balance_after <= 0) continue;

        // LAST TERM EXCEPTION: do not apply carry rule to the final term.
        // Leave it as partial — the student pays the remainder next time.
        if (entry.term_order >= maxTermOrder) continue;

        const carryoverCents = toCents(entry.balance_after);
        if (carryoverCents <= 0) continue;

        // Find the first term in the ORIGINAL sorted list AFTER this one
        // that still has balance > 0 (may be outside the Step 1 allocation scope —
        // e.g. Midterm was not reached because the payment ran out on Prelim).
        const nextTerm = sorted.find(
            (t) => t.term_order > entry.term_order && toCents(t.balance) > 0,
        ) ?? null;

        if (!nextTerm) {
            // No eligible next term with balance (can happen when subsequent terms
            // were fully paid in Step 1 of this same payment). Skip.
            continue;
        }

        // Close this term and annotate the carry.
        entry.processed       = true;
        entry.carried_forward = fromCents(carryoverCents);
        entry.carried_to_term = nextTerm.term_name;
        entry.balance_after   = 0;    // server will zero this term's balance
        entry.fully_paid      = false; // processed ≠ paid
    }

    return result;
}

// ─────────────────────────────────────────────────────────────────────────────
// DISPLAY FORMATTING (output only — never pipe back into calculations)
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Format an amount in pesos for display.
 *
 * Accepts pesos (not cents). For display ONLY — never feed result into math.
 *
 * Examples:
 *   formatCurrency(5000)    === "₱5,000.00"
 *   formatCurrency(921.80)  === "₱921.80"
 *   formatCurrency(null)    === "₱0.00"
 */
export function formatCurrency(amount: number | null | undefined, showSymbol = true): string {
    if (amount === null || amount === undefined) {
        return showSymbol ? '₱0.00' : '0.00';
    }
    try {
        const num       = typeof amount === 'number' ? amount : parseFloat(String(amount));
        const formatted = num.toLocaleString('en-US', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        });
        return showSymbol ? `₱${formatted}` : formatted;
    } catch {
        return showSymbol ? `₱${Number(amount).toFixed(2)}` : Number(amount).toFixed(2);
    }
}

/**
 * useMoney composable — exposes all money utilities in a single import.
 */
export function useMoney() {
    return {
        toCents,
        fromCents,
        formatCurrency,
        allocatePayment,
    };
}