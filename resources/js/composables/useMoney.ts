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
 * The result is a JavaScript number (float) for use with Intl.NumberFormat,
 * or for JSON serialization to the backend. Do not subtract, add, or compare
 * this value — go through toCents() again if you need to recalculate.
 *
 * Examples:
 *   fromCents(500000) === 5000
 *   fromCents(407820) === 4078.2  (use formatCurrency() for display)
 *   fromCents(92180)  === 921.8   (use formatCurrency() for display)
 */
export function fromCents(cents: number): number {
    return cents / 100;
}

/**
 * Convert integer cents to a peso string suitable for JSON submission.
 *
 * Returns "5000.00" not 5000.0 — always 2 decimal places.
 * Use this when submitting amounts to the backend API.
 */
export function centsToDecimalString(cents: number): string {
    const abs     = Math.abs(cents);
    const whole   = Math.floor(abs / 100);
    const decimal = abs % 100;
    const str     = `${whole}.${String(decimal).padStart(2, '0')}`;
    return cents < 0 ? `-${str}` : str;
}

// ─────────────────────────────────────────────────────────────────────────────
// ARITHMETIC (all in integer cents)
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Sum an array of cent values.
 */
export function sumCents(centValues: number[]): number {
    return centValues.reduce((acc, c) => acc + c, 0);
}

/**
 * Apply a percentage to a cents amount, returning integer cents.
 *
 * Examples:
 *   percentCents(1230200, 30) === 369060   (₱3,690.60)
 *   percentCents(1230200, 25) === 307550   (₱3,075.50)
 */
export function percentCents(cents: number, percentage: number): number {
    return Math.round(cents * (percentage / 100));
}

/**
 * Payment allocation engine — distributes an amount across term balances.
 *
 * All arithmetic is integer-cents. Returns an allocation ledger identical
 * to what the PHP backend will compute.
 *
 * @param amountCents   Total payment in integer cents.
 * @param terms         Array of { id, term_name, term_order, balance } sorted by term_order.
 * @returns AllocationEntry[] — one per affected term.
 */
export interface AllocationEntry {
    term_id:        number;
    term_name:      string;
    term_order:     number;
    balance_before: number;  // in pesos (for display)
    applied:        number;  // in pesos (for display)
    balance_after:  number;  // in pesos (for display)
    applied_cents:  number;  // raw cents (for calculations)
    fully_paid:     boolean;
}

export interface AllocatableTerm {
    id:         number;
    term_name:  string;
    term_order: number;
    balance:    number;  // in pesos
}

export function allocatePayment(
    amountCents: number,
    terms: AllocatableTerm[],
): AllocationEntry[] {
    const result: AllocationEntry[] = [];
    let remainingCents = amountCents;

    const sorted = [...terms].sort((a, b) => a.term_order - b.term_order);

    for (const term of sorted) {
        if (remainingCents <= 0) break;

        const balanceBeforeCents = toCents(term.balance);
        const appliedCents       = Math.min(remainingCents, balanceBeforeCents);
        const balanceAfterCents  = balanceBeforeCents - appliedCents;

        result.push({
            term_id:        term.id,
            term_name:      term.term_name,
            term_order:     term.term_order,
            balance_before: fromCents(balanceBeforeCents),
            applied:        fromCents(appliedCents),
            balance_after:  fromCents(balanceAfterCents),
            applied_cents:  appliedCents,
            fully_paid:     balanceAfterCents === 0,
        });

        remainingCents -= appliedCents;
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
 * Format integer cents for display.
 *
 * Examples:
 *   formatCents(500000)  === "₱5,000.00"
 *   formatCents(92180)   === "₱921.80"
 */
export function formatCents(cents: number, showSymbol = true): string {
    return formatCurrency(fromCents(cents), showSymbol);
}

// ─────────────────────────────────────────────────────────────────────────────
// COMPOSABLE EXPORT
// ─────────────────────────────────────────────────────────────────────────────

export function useMoney() {
    return {
        // Input conversion
        toCents,
        roundToCents: toCents, // alias for clarity

        // Output conversion
        fromCents,
        centsToDecimalString,

        // Arithmetic
        sumCents,
        percentCents,
        allocatePayment,

        // Display
        formatCurrency,
        formatCents,
    };
}
