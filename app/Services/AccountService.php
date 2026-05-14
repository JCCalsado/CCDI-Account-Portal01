<?php

namespace App\Services;

use App\Models\Account;
use App\Models\StudentPaymentTerm;
use App\Models\User;
use App\Services\MoneyService;
use Illuminate\Support\Facades\DB;

/**
 * Single authoritative writer for student account balances.
 *
 * INVARIANT: `accounts.balance` is the ONE source of truth.
 *   - Every balance read goes through `$user->account->balance`.
 *   - This service is the ONLY writer of that column.
 *   - `students.total_balance` has been removed (migration 2026_03_17_000001).
 *
 * Call `recalculate()` after any event that changes the financial position:
 *   - After StudentPaymentService::processPayment / finalizeApprovedPayment
 *   - After StudentFeeController::store / storePayment / update
 *
 * NOTE: Auto-promotion of year_level has been REMOVED from this service.
 * Year-level advancement is admin-driven via the workflow system
 * (students.advance-workflow route → StudentController::advanceWorkflow).
 *
 * The previous promoteStudent() call was incorrect because:
 *   - A student finishes paying 1st Year 1st Sem → balance hits 0.
 *   - They are NOT yet in 2nd Year — they still have 1st Year 2nd Sem.
 *   - Year level is an academic status, not a financial status.
 *   - Tying it to balance created silent data corruption on every full payoff.
 */
class AccountService
{
    /**
     * Recompute and persist the authoritative balance for a user.
     *
     * Balance = SUM(StudentPaymentTerm.balance) across all active assessments.
     *
     * Positive  → student owes money.
     * Zero      → fully paid.
     *
     * @param  User|null  $user  Null-safe: no-op when null.
     */
    public static function recalculate(?User $user): void
    {
        if (! $user) {
            return;
        }

        // Sum outstanding term balances across all active assessments.
        // Uses MoneyService::sumFromDb() to safely convert MySQL decimal aggregate
        // to integer cents, avoiding float-cast precision loss.
        $dbSum = StudentPaymentTerm::whereHas(
            'assessment',
            fn ($q) => $q->where('user_id', $user->id)->where('status', 'active')
        )->sum('balance');

        $balanceCents = MoneyService::sumFromDb($dbSum);
        $balanceDecimal = MoneyService::toPesos($balanceCents);

        // ── Single source of truth: accounts.balance ──────────────────────────
        // Guard against orphan account creation that bypasses generateAccountNumber().
        // If no account record exists, create one properly inside a transaction so
        // the pessimistic lock in generateAccountNumber() is effective.
        $account = $user->account;

        if (! $account) {
            DB::transaction(function () use ($user, &$account) {
                $accountNumber = Account::generateAccountNumber();
                $account = $user->account()->create([
                    'account_number' => $accountNumber,
                    'balance'        => 0,
                ]);
            });

            // Reload the fresh instance after the inner transaction committed.
            $account = $user->account()->first();
        }

        $account->update(['balance' => $balanceDecimal]);

        // NOTE: students.total_balance has been REMOVED (migration 2026_03_17_000001).
        // There is no second write here. accounts.balance is the only balance column.

        // NOTE: promoteStudent() has been removed.
        // Year-level promotion is admin-driven via StudentController::advanceWorkflow().
        // See: routes/web.php → students.advance-workflow
    }
}