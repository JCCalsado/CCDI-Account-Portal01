<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Add 'underpaid' status to student_payment_terms.
 *
 * WHAT THIS DOES
 * ──────────────
 * 1. Converts existing last-term PARTIAL rows (balance > 0) → 'underpaid'.
 *    These are the rows corrupted by the old close-and-carry bug where the
 *    final term was incorrectly zeroed. RecoverFinalTermBalance previously
 *    restored them to 'partial'. This migration promotes them to 'underpaid',
 *    which is the correct semantic for "final term, balance remains, student
 *    must pay more."
 *
 * 2. Updates the column COMMENT to document all valid status values including
 *    'underpaid'. The column is VARCHAR(255) — no enum redefinition needed.
 *
 * STATUS VOCABULARY AFTER THIS MIGRATION
 * ───────────────────────────────────────
 *   unpaid    → legacy initial value (pre-normalisation); treated as pending
 *   pending   → term not yet paid; canonical initial value
 *   partial   → LEGACY: partial payment on a mid-term before carry rule ran
 *   underpaid → last term received a partial payment; remaining balance stays
 *               here; no next term to carry to; student must pay the remainder
 *   paid      → balance = 0, fully settled
 *   processed → balance = 0, partial payment carried forward to next term (closed)
 *
 * IDEMPOTENT
 * ──────────
 * The UPDATE only targets rows that are currently 'partial' with balance > 0
 * AND are the last term in their assessment (MAX term_order). Re-running this
 * migration is safe.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Convert last-term PARTIAL rows → UNDERPAID ────────────────────────
        // We use a subquery join to identify only the final term per assessment
        // (the one with the highest term_order). We only touch rows that are
        // currently PARTIAL with balance > 0 — any other status is left alone.
        if (DB::getDriverName() === 'mysql') {
            $affected = DB::statement("
                UPDATE student_payment_terms spt
                INNER JOIN (
                    SELECT student_assessment_id, MAX(term_order) AS max_order
                    FROM student_payment_terms
                    GROUP BY student_assessment_id
                ) last_terms
                    ON spt.student_assessment_id = last_terms.student_assessment_id
                   AND spt.term_order            = last_terms.max_order
                SET
                    spt.status  = 'underpaid',
                    spt.remarks = CONCAT(
                        COALESCE(spt.remarks, ''),
                        IF(spt.remarks IS NOT NULL AND TRIM(spt.remarks) != '', '. ', ''),
                        'Status normalised from partial \u2192 underpaid by migration on ',
                        NOW()
                    )
                WHERE spt.status  = 'partial'
                  AND spt.balance > 0
            ");

            // Log how many rows were affected for the deployment audit trail.
            // Cast to int — DB::statement() returns bool, not row count on MySQL.
            // Use a follow-up SELECT to get the count for logging.
            $count = DB::table('student_payment_terms')
                ->where('status', 'underpaid')
                ->count();

            Log::info("Migration add_underpaid_status: {$count} underpaid term(s) now in DB after normalisation.");

            // ── Update column comment ─────────────────────────────────────────
            DB::statement("
                ALTER TABLE student_payment_terms
                MODIFY COLUMN `status` VARCHAR(255) DEFAULT 'pending'
                COMMENT 'unpaid (legacy) | pending | partial (legacy mid-term) | underpaid (last term, balance remains) | paid | processed (balance carried to next term, closed)'
            ");
        } else {
            // SQLite / other drivers: UPDATE without DDL.
            //
            // We cannot use MAX(id) here — insertion order is not the same as
            // term_order. A term inserted last may not be the final term in the
            // payment sequence. We must join on MAX(term_order) to correctly
            // identify the last term per assessment.
            //
            // SQLite does not support the INNER JOIN ... UPDATE syntax used in the
            // MySQL path, so we use a correlated subquery instead.
            DB::table('student_payment_terms as spt')
                ->where('spt.status', 'partial')
                ->where('spt.balance', '>', 0)
                ->whereRaw('spt.term_order = (
                    SELECT MAX(inner_spt.term_order)
                    FROM student_payment_terms AS inner_spt
                    WHERE inner_spt.student_assessment_id = spt.student_assessment_id
                )')
                ->update(['status' => 'underpaid']);
        }
    }

    public function down(): void
    {
        // Revert underpaid → partial. Balance is unchanged; the debt is intact.
        DB::table('student_payment_terms')
            ->where('status', 'underpaid')
            ->update(['status' => 'partial']);

        if (DB::getDriverName() === 'mysql') {
            DB::statement("
                ALTER TABLE student_payment_terms
                MODIFY COLUMN `status` VARCHAR(255) DEFAULT 'pending'
                COMMENT 'unpaid (legacy) | pending | partial (legacy) | paid | processed (balance carried to next term, closed)'
            ");
        }
    }
};