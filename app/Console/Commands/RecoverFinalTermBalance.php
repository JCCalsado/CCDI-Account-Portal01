<?php

namespace App\Console\Commands;

use App\Enums\PaymentStatus;
use App\Models\StudentAssessment;
use App\Models\StudentPaymentTerm;
use App\Models\Transaction;
use App\Services\AccountService;
use App\Services\MoneyService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * RecoverFinalTermBalance
 *
 * ONE-TIME recovery command for the specific bug where the close-and-carry
 * rule in StudentPaymentService::allocatePaymentAcrossTerms() Step 2
 * incorrectly zeroed the LAST term of an assessment (usually "Final") when
 * no next term existed.
 *
 * THE BUG
 * ───────
 * Step 2 of allocatePaymentAcrossTerms() called $closedTerm->update([
 *     'balance' => '0.00',
 *     'status'  => PaymentStatus::PROCESSED->value,
 * ]) on the last term even when there was no next term to receive the carry.
 * This destroyed the remaining balance permanently: AccountService::recalculate()
 * then reported ₱0.00 outstanding, blocking further payments.
 *
 * ROOT CAUSE FIX (applied separately)
 * ────────────────────────────────────
 * StudentPaymentService::allocatePaymentAcrossTerms() Step 2 now guards:
 *   if ($alloc['term_order'] === (int) $maxTermOrder) { continue; }
 * This command recovers data for assessments corrupted BEFORE that fix.
 *
 * HOW TO USE
 * ──────────
 *   # Preview changes for ALL students (no DB writes):
 *   php artisan payments:recover-final-term --dry-run
 *
 *   # Preview for one student only:
 *   php artisan payments:recover-final-term --dry-run --user-id=103
 *
 *   # Apply the recovery:
 *   php artisan payments:recover-final-term --execute
 *
 *   # Apply for one student only:
 *   php artisan payments:recover-final-term --execute --user-id=103
 *
 * WHAT IT DOES
 * ────────────
 * For every ACTIVE assessment where the last term (max term_order) is
 * PROCESSED with balance = 0 but total paid < total_assessment:
 *
 *   correct_balance = total_assessment − total_actually_paid
 *   missing         = correct_balance − sum_of_current_non_zero_term_balances
 *
 * Restores:
 *   last_term.balance   = missing  (the balance the bug destroyed)
 *   last_term.status    = 'partial'
 *   last_term.paid_date = null
 *   last_term.remarks   = audit note with recovery timestamp
 *
 * Then runs AccountService::recalculate() to sync accounts.balance.
 *
 * IDEMPOTENT
 * ──────────
 * A term with status = 'partial' and balance > 0 is skipped automatically.
 * Safe to re-run: already-recovered terms are never double-counted.
 */
class RecoverFinalTermBalance extends Command
{
    protected $signature = 'payments:recover-final-term
                            {--dry-run   : Preview changes without writing to the database}
                            {--execute   : Apply the recovery (required to write)}
                            {--user-id=  : Limit recovery to a specific student user ID}';

    protected $description = 'Recover Final term balances incorrectly zeroed by the close-and-carry bug.';

    public function handle(): int
    {
        $isDryRun   = $this->option('dry-run');
        $isExecute  = $this->option('execute');
        $filterUser = $this->option('user-id') ? (int) $this->option('user-id') : null;

        // ── Guard: require exactly one mode flag ──────────────────────────────
        if (! $isDryRun && ! $isExecute) {
            $this->error('You must specify either --dry-run or --execute.');
            $this->line('  Preview:  php artisan payments:recover-final-term --dry-run');
            $this->line('  Apply:    php artisan payments:recover-final-term --execute');
            return self::FAILURE;
        }

        if ($isDryRun && $isExecute) {
            $this->error('Cannot combine --dry-run and --execute. Choose one.');
            return self::FAILURE;
        }

        $mode = $isDryRun ? 'DRY RUN' : 'EXECUTE';

        $this->line('');
        $this->info('══════════════════════════════════════════════════════════');
        $this->info("  RecoverFinalTermBalance — {$mode}");
        if ($filterUser) {
            $this->info("  Scope: user_id = {$filterUser}");
        } else {
            $this->info('  Scope: ALL active student assessments');
        }
        $this->info('══════════════════════════════════════════════════════════');
        $this->line('');

        // ── Load candidate assessments ────────────────────────────────────────
        // Only active assessments are in-scope. Archived/cancelled assessments
        // have no payable terms; modifying them would corrupt historical data.
        $query = StudentAssessment::with(['paymentTerms', 'user'])
            ->where('status', 'active');

        if ($filterUser) {
            $query->where('user_id', $filterUser);
        }

        $assessments  = $query->get();
        $totalFixed   = 0;
        $totalSkipped = 0;
        $totalErrors  = 0;

        foreach ($assessments as $assessment) {
            /** @var \App\Models\StudentAssessment $assessment */
            $terms = $assessment->paymentTerms->sortBy('term_order');

            if ($terms->isEmpty()) {
                $totalSkipped++;
                continue;
            }

            // The final term is the one with the highest term_order.
            $lastTerm = $terms->last();

            // ── Skip guard: only target PROCESSED terms with zero balance ─────
            // A legitimately PROCESSED term (a non-last term that was correctly
            // closed and carried) also has status='processed' and balance=0.
            // We discriminate by checking that it IS the last term (already done
            // by taking $terms->last()) AND that it has no incoming carry target
            // (because the bug fires only when there is no next term).
            if ($lastTerm->status !== PaymentStatus::PROCESSED->value) {
                $totalSkipped++;
                continue;
            }

            if (MoneyService::toCents($lastTerm->balance) !== 0) {
                // Last term is processed but has balance — unexpected, skip safely.
                $totalSkipped++;
                continue;
            }

            // ── Compute total assessed ────────────────────────────────────────
            $totalAssessmentCents = MoneyService::toCents($assessment->total_assessment);

            if ($totalAssessmentCents <= 0) {
                // Zero-amount assessment — nothing to recover.
                $totalSkipped++;
                continue;
            }

            // ── Compute total actually paid ───────────────────────────────────
            // PRIMARY: match by assessment_id stored in transaction meta.
            // The (int) cast is required — MySQL JSON type-matching treats '1' and
            // 1 as different values; without it, whereJsonContains returns 0 rows
            // on some MySQL versions even when the data is correct.
            $totalPaidCents = MoneyService::sumFromDb(
                Transaction::where('user_id', $assessment->user_id)
                    ->where('kind', 'payment')
                    ->where('status', PaymentStatus::PAID->value)
                    ->whereJsonContains('meta->assessment_id', (int) $assessment->id)
                    ->sum('amount')
            );

            // FALLBACK: if the JSON query returns 0 paid (which can happen for
            // older transactions that pre-date the assessment_id meta field, or
            // when recorded via StudentFeeController which uses a different flow),
            // fall back to matching by user + year + semester.
            // We extract the start year from school_year (e.g. '2026' from '2026-2027').
            if ($totalPaidCents === 0) {
                $yearStart  = explode('-', $assessment->school_year, 2)[0] ?? null;
                $semester   = $assessment->semester;

                if ($yearStart && $semester) {
                    $totalPaidCents = MoneyService::sumFromDb(
                        Transaction::where('user_id', $assessment->user_id)
                            ->where('kind', 'payment')
                            ->where('status', PaymentStatus::PAID->value)
                            ->where('year', $yearStart)
                            ->where('semester', $semester)
                            ->sum('amount')
                    );
                }
            }

            // ── Derive correct remaining balance ──────────────────────────────
            // What the student should still owe = assessed total − all confirmed payments.
            $correctRemainingCents = $totalAssessmentCents - $totalPaidCents;

            if ($correctRemainingCents <= 0) {
                // Student paid in full. The last term was correctly zeroed by payment —
                // this is NOT the bug scenario. Skip.
                $totalSkipped++;
                continue;
            }

            // ── Sum of balances currently recorded on non-zero terms ──────────
            // If other terms already hold some of the remaining balance (e.g., a
            // midterm partial-pay scenario where carry IS correct), we must only
            // restore what is genuinely missing from the last term.
            $currentTermBalanceCents = MoneyService::sumFromDb(
                StudentPaymentTerm::where('student_assessment_id', $assessment->id)
                    ->where('balance', '>', 0)
                    ->sum('balance')
            );

            // The balance that should be on the last term but was destroyed by the bug.
            $missingCents = $correctRemainingCents - $currentTermBalanceCents;

            if ($missingCents <= 0) {
                // The correct balance is already distributed across other terms.
                // Last term was zeroed correctly (or another mechanism compensated).
                $totalSkipped++;
                continue;
            }

            // ── Sanity check: warn if the discrepancy is suspiciously large ───
            // Missing more than the original last-term amount is a red flag —
            // it could indicate double-payment records or other data corruption.
            $originalLastTermAmountCents = MoneyService::toCents($lastTerm->amount);
            if ($missingCents > $originalLastTermAmountCents) {
                $this->warn(sprintf(
                    '  ⚠  Assessment #%d: missing (%s) > original term amount (%s). ' .
                    'Possible data anomaly — verify manually before executing.',
                    $assessment->id,
                    MoneyService::formatFromCents($missingCents),
                    MoneyService::formatFromCents($originalLastTermAmountCents)
                ));
            }

            // ── Output diagnostic block ───────────────────────────────────────
            $this->line(sprintf(
                '  Assessment #%d | %s | %s',
                $assessment->id,
                $assessment->user?->account_id ?? 'no-account-id',
                $assessment->user?->name ?? 'Unknown Student'
            ));
            $this->line(sprintf(
                '    Term:        %s | %s',
                $assessment->school_year,
                $assessment->semester
            ));
            $this->line(sprintf(
                '    Last term:   "%s" (order=%d, status=%s, balance=₱0.00)',
                $lastTerm->term_name,
                $lastTerm->term_order,
                $lastTerm->status
            ));
            $this->line(sprintf(
                '    Assessed:    %s',
                MoneyService::formatFromCents($totalAssessmentCents)
            ));
            $this->line(sprintf(
                '    Paid:        %s',
                MoneyService::formatFromCents($totalPaidCents)
            ));
            $this->line(sprintf(
                '    Other terms (current balance): %s',
                MoneyService::formatFromCents($currentTermBalanceCents)
            ));
            $this->line(sprintf(
                '    → Will restore to last term:  %s',
                MoneyService::formatFromCents($missingCents)
            ));

            if ($isDryRun) {
                $this->warn(sprintf(
                    '    [DRY RUN] Would set "%s" → balance=%s, status=partial',
                    $lastTerm->term_name,
                    MoneyService::formatFromCents($missingCents)
                ));
                $totalFixed++;
                $this->line('');
                continue;
            }

            // ── Execute recovery inside a transaction ─────────────────────────
            try {
                DB::transaction(function () use ($lastTerm, $missingCents, $assessment) {

                    // Re-lock the term row for the duration of this transaction.
                    $locked = StudentPaymentTerm::lockForUpdate()->findOrFail($lastTerm->id);

                    // Idempotency guard: if another process already recovered this
                    // term between our read and the lock, skip the update.
                    if (MoneyService::toCents($locked->balance) > 0
                        && $locked->status !== PaymentStatus::PROCESSED->value) {
                        Log::info('RecoverFinalTermBalance: term already recovered, skipping', [
                            'term_id'   => $locked->id,
                            'term_name' => $locked->term_name,
                        ]);
                        return;
                    }

                    $locked->update([
                        'balance'   => MoneyService::toPesos($missingCents),
                        'status'    => PaymentStatus::PARTIAL->value,
                        'paid_date' => null,
                        // Leave an audit trail — the original bug remark will be overwritten.
                        'remarks'   => sprintf(
                            'Balance restored by payments:recover-final-term on %s. ' .
                            'Was incorrectly zeroed by carry-forward bug (no next term).',
                            now()->toDateTimeString()
                        ),
                    ]);

                    // Resync accounts.balance so the student portal shows the correct
                    // outstanding amount immediately after recovery.
                    AccountService::recalculate($assessment->user);

                    Log::info('RecoverFinalTermBalance: restored', [
                        'term_id'       => $locked->id,
                        'term_name'     => $locked->term_name,
                        'assessment_id' => $assessment->id,
                        'user_id'       => $assessment->user_id,
                        'restored'      => MoneyService::formatFromCents($missingCents),
                        'new_status'    => PaymentStatus::PARTIAL->value,
                    ]);
                });

                // ── Post-fix verification: read back the saved value ──────────
                $lastTerm->refresh();
                $verifiedCents = MoneyService::toCents($lastTerm->balance);

                if ($verifiedCents === $missingCents) {
                    $this->info(sprintf(
                        '    ✓ RESTORED — "%s" balance: %s (status: %s)',
                        $lastTerm->term_name,
                        MoneyService::formatFromCents($verifiedCents),
                        $lastTerm->status
                    ));
                } else {
                    $this->error(sprintf(
                        '    ✗ MISMATCH after write — expected %s, got %s. Manual review required.',
                        MoneyService::formatFromCents($missingCents),
                        MoneyService::formatFromCents($verifiedCents)
                    ));
                    $totalErrors++;
                    $this->line('');
                    continue;
                }

                $totalFixed++;

            } catch (\Exception $e) {
                $this->error('    ✗ ERROR: ' . $e->getMessage());
                Log::error('RecoverFinalTermBalance: failed', [
                    'assessment_id' => $assessment->id,
                    'term_id'       => $lastTerm->id,
                    'error'         => $e->getMessage(),
                    'trace'         => $e->getTraceAsString(),
                ]);
                $totalErrors++;
            }

            $this->line('');
        }

        // ── Summary ───────────────────────────────────────────────────────────
        $this->info('══════════════════════════════════════════════════════════');
        $this->info("  Summary ({$mode})");
        $this->line('');
        $this->info(sprintf('  %-20s %d', $isDryRun ? 'Would recover:' : 'Recovered:', $totalFixed));
        $this->info(sprintf('  %-20s %d', 'Skipped (clean):', $totalSkipped));

        if ($totalErrors > 0) {
            $this->error(sprintf('  %-20s %d  ← check laravel.log', 'Errors:', $totalErrors));
        } else {
            $this->info(sprintf('  %-20s %d', 'Errors:', $totalErrors));
        }

        $this->info('══════════════════════════════════════════════════════════');

        if ($isDryRun && $totalFixed > 0) {
            $this->line('');
            $this->warn("  This was a DRY RUN. No changes were written.");
            $this->warn("  To apply: php artisan payments:recover-final-term --execute");
            if ($filterUser) {
                $this->warn("            php artisan payments:recover-final-term --execute --user-id={$filterUser}");
            }
        }

        if ($isExecute && $totalFixed > 0) {
            $this->line('');
            $this->info('  Recovery complete. accounts.balance has been resynced for all affected students.');
            $this->info('  Verify in the student portal: Student Account → Term Breakdown → Final should now show balance.');
        }

        $this->line('');

        return $totalErrors > 0 ? self::FAILURE : self::SUCCESS;
    }
}