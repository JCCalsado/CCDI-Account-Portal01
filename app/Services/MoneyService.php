<?php

namespace App\Services;

/**
 * MoneyService — Integer-Cents Monetary Arithmetic
 *
 * PURPOSE
 * ───────
 * Eliminates ALL floating-point precision errors from monetary calculations.
 * IEEE 754 double-precision floats cannot represent most decimal fractions
 * exactly. For example:
 *
 *     (float) '4078.20' === 4078.1999999999997...  (PHP internal)
 *     5000.00 - 4078.20 === 921.7999999999997...   (float subtraction)
 *
 * MoneyService converts every peso amount to integer cents at the system
 * boundary and performs ALL arithmetic in integers. Integers are exact.
 *
 * RULES
 * ─────
 *   ✅  DO use this service for every monetary calculation.
 *   ✅  DO call toCents() immediately at input boundaries (user input, DB read).
 *   ✅  DO call toPesos() / toDecimalString() only for DB write or display.
 *   ❌  NEVER pass raw float/double values into calculation logic.
 *   ❌  NEVER use number_format(), round(), or bcmath directly in services.
 *   ❌  NEVER use the toFloat() helper in calculation logic — display ONLY.
 *
 * EXAMPLE
 * ───────
 *   // OLD (broken)
 *   $remaining = 5000.00 - 4078.20;        // === 921.7999999999997
 *   $balance   = round($remaining, 2);      // === 921.80 (sometimes)
 *
 *   // NEW (exact)
 *   $remainingCents = 500000 - 407820;      // === 92180  (exact integer)
 *   $balance = MoneyService::toPesos(92180); // === "921.80" (exact string)
 */
final class MoneyService
{
    // ─────────────────────────────────────────────────────────────────────────
    // INPUT BOUNDARY: Convert to Integer Cents
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Convert any monetary input to integer cents.
     *
     * Accepts: PHP float, int, string from user input, or MySQL decimal string.
     *
     * Strategy:
     *   1. Strip non-numeric characters (₱, commas, spaces).
     *   2. Parse integer and decimal parts separately — zero float arithmetic.
     *   3. Return (whole * 100) + decimal_cents.
     *
     * Examples:
     *   toCents(5000)        === 500000
     *   toCents(5000.00)     === 500000
     *   toCents('4078.20')   === 407820
     *   toCents('4999.994')  === 499999   ← truncates sub-cent, use roundToCents() if needed
     *   toCents('₱1,234.56') === 123456
     */
    public static function toCents(mixed $amount): int
    {
        // Clean input: strip currency symbols and thousands separators.
        $cleaned = preg_replace('/[^0-9.\-]/', '', (string) $amount);

        if ($cleaned === '' || $cleaned === '-') {
            return 0;
        }

        $negative = str_starts_with($cleaned, '-');
        $cleaned  = ltrim($cleaned, '-');

        // Split on decimal point.
        $parts   = explode('.', $cleaned, 2);
        $whole   = (int) ($parts[0] ?: '0');
        $decStr  = $parts[1] ?? '00';

        // Pad or truncate to exactly 2 decimal digits.
        $decStr = str_pad(substr($decStr, 0, 2), 2, '0', STR_PAD_RIGHT);
        $cents  = $whole * 100 + (int) $decStr;

        return $negative ? -$cents : $cents;
    }

    /**
     * Convert any monetary input to integer cents, rounding the sub-cent portion.
     *
     * Use this at external input boundaries (user-entered amounts, API amounts)
     * where sub-cent fractions should be rounded to the nearest cent.
     *
     * Examples:
     *   roundToCents(4999.994)  === 499999   (rounds down)
     *   roundToCents(4999.995)  === 500000   (rounds up)
     *   roundToCents(5000.0)    === 500000
     */
    public static function roundToCents(mixed $amount): int
    {
        // Use number_format to correctly round to 2 decimal places first,
        // then parse with toCents for exact integer conversion.
        $rounded = number_format((float) $amount, 2, '.', '');
        return self::toCents($rounded);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // OUTPUT BOUNDARY: Convert from Integer Cents
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Convert integer cents to a decimal string suitable for DB storage.
     *
     * Returns a string like "921.80" — zero float arithmetic, always exact.
     * Compatible with decimal(12,2) MySQL columns.
     *
     * Examples:
     *   toPesos(92180)  === "921.80"
     *   toPesos(500000) === "5000.00"
     *   toPesos(0)      === "0.00"
     */
    public static function toPesos(int $cents): string
    {
        $negative = $cents < 0;
        $abs      = abs($cents);
        $whole    = intdiv($abs, 100);
        $fraction = $abs % 100;
        $str      = sprintf('%d.%02d', $whole, $fraction);
        return $negative ? '-' . $str : $str;
    }

    /**
     * Convert integer cents to float.
     *
     * ⚠️  FOR DISPLAY / SERIALIZATION ONLY. Never use the result in arithmetic.
     *
     * This re-introduces float representation, which is acceptable for JSON
     * responses and display but not for calculations.
     */
    public static function toFloat(int $cents): float
    {
        return $cents / 100;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ARITHMETIC (all in integer cents)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Sum an array of cent values.
     *
     * @param  int[]  $centValues
     */
    public static function sum(array $centValues): int
    {
        return array_sum($centValues);
    }

    /**
     * Apply a percentage to a cents amount, returning integer cents.
     *
     * Uses PHP_ROUND_HALF_UP for banker-safe rounding.
     *
     * Examples:
     *   percent(1230200, 30.0)  === 369060   (₱3,690.60)
     *   percent(1230200, 25.0)  === 307550   (₱3,075.50)
     */
    public static function percent(int $cents, float $percentage): int
    {
        return (int) round($cents * ($percentage / 100), 0, PHP_ROUND_HALF_UP);
    }

    /**
     * Distribute a total cent amount across percentage slices.
     *
     * The last slice absorbs any rounding remainder so the sum of all slices
     * always equals the input $totalCents exactly — no penny lost or gained.
     *
     * @param  int    $totalCents   The total amount in integer cents to distribute.
     * @param  float[] $percentages  Array of percentages (must sum to 100).
     * @return int[]                 Array of cent amounts, same length as $percentages.
     *
     * Example:
     *   distribute(1230200, [30, 30, 25, 15])
     *   → [369060, 369060, 307550, 184530]  (sums to 1230200 exactly)
     */
    public static function distribute(int $totalCents, array $percentages): array
    {
        $results     = [];
        $distributed = 0;
        $count       = count($percentages);

        foreach ($percentages as $i => $pct) {
            $isLast = ($i === $count - 1);

            if ($isLast) {
                // Last slice absorbs rounding remainder — exact.
                $results[] = $totalCents - $distributed;
            } else {
                $slice      = self::percent($totalCents, (float) $pct);
                $results[]  = $slice;
                $distributed += $slice;
            }
        }

        return $results;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // DISPLAY FORMATTING (output only — never pipe these back into calculations)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Format an amount for human display.
     *
     * Accepts either integer cents or a peso string/float (for backwards compat).
     *
     * Examples:
     *   formatFromCents(500000)   === "₱5,000.00"
     *   formatFromPesos('921.80') === "₱921.80"
     */
    public static function formatFromCents(int $cents): string
    {
        return '₱' . number_format(self::toFloat($cents), 2);
    }

    public static function formatFromPesos(mixed $amount): string
    {
        return '₱' . number_format((float) $amount, 2);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // SAFE DB SUM (prevents float cast issues from MySQL decimal aggregates)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Sum DB decimal values (as returned by Eloquent's sum()) into integer cents.
     *
     * MySQL sum() on decimal(12,2) columns returns a string like "4999.99".
     * Casting directly to (float) can introduce sub-cent errors.
     * This method bypasses floats entirely.
     *
     * Usage:
     *   $cents = MoneyService::sumFromDb(
     *       StudentPaymentTerm::where(...)->sum('balance')
     *   );
     */
    public static function sumFromDb(mixed $dbResult): int
    {
        // DB sum() returns numeric string — parse without float.
        return self::toCents((string) ($dbResult ?? '0'));
    }
}
