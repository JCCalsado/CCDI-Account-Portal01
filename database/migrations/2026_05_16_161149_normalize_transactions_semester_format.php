<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Normalize transactions.semester from legacy long format to short format.
 *
 * WHY THIS EXISTS:
 *   The original PaymentController::getCurrentSemesterLabel() returned '1st Sem' / '2nd Sem'.
 *   The FinancialReportsController (post-fix) queries ->where('semester', '1st').
 *   All historical transactions stored '1st Sem' are invisible to those queries,
 *   causing totalPaid, byMonthSummary, and paymentMethods to return ₱0 / empty.
 *
 * WHAT IT DOES:
 *   '1st Sem'  → '1st'
 *   '2nd Sem'  → '2nd'
 *   'Summer'   → unchanged (already correct)
 *   null       → unchanged
 *
 * SAFE TO RE-RUN: The WHERE clause is exact-match on the old values only.
 */
return new class extends Migration
{
    public function up(): void
    {
        // '1st Sem' → '1st'
        $updated1st = DB::table('transactions')
            ->where('semester', '1st Sem')
            ->update(['semester' => '1st']);

        // '2nd Sem' → '2nd'
        $updated2nd = DB::table('transactions')
            ->where('semester', '2nd Sem')
            ->update(['semester' => '2nd']);

        // Log results for visibility during migration run
        \Illuminate\Support\Facades\Log::info('normalize_transactions_semester: migration complete', [
            'updated_1st_sem' => $updated1st,
            'updated_2nd_sem' => $updated2nd,
        ]);
    }

    public function down(): void
    {
        // Revert: short → long format (restores old broken state for rollback only)
        DB::table('transactions')
            ->where('semester', '1st')
            ->update(['semester' => '1st Sem']);

        DB::table('transactions')
            ->where('semester', '2nd')
            ->update(['semester' => '2nd Sem']);
    }
};