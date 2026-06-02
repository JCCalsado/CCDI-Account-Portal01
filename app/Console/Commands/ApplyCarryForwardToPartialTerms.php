<?php

namespace App\Console\Commands;

use App\Enums\PaymentStatus;
use App\Models\StudentPaymentTerm;
use App\Services\AccountService;
use App\Services\MoneyService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ApplyCarryForwardToPartialTerms
 *
 * Backfill command that finds existing PARTIAL terms in the database and
 * applies the correct rule retroactively based on whether a next term exists.
 *
 * TWO CASES, TWO OUTCOMES
 * ───────────────────────
 * MID-TERM PARTIAL (next term exists):
 *   → Apply the close-and-carry rule.
 *   → Carry the remaining balance to the next term.
 *   → Close this term: balance = 0, status = 'processed'.
 *   → SUM(all term balances) unchanged — money moved, not destroyed.
 *
 * LAST-TERM PARTIAL (no next term):
 *   → This is the canonical UNDERPAID scenario.
 *   → The balance stays on this term.
 *   → Set status = 'underpaid'.
 *   → SUM(all term balances) unchanged — nothing moved, nothing lost.
 *   → Student must pay the remainder in a future transaction.
 *
 * USAGE
 * ─────
 *   # Dry run — shows what WOULD happen, makes no DB changes:
 *   php artisan payments:apply-carry-forward --dry-run
 *
 *   # Execute:
 *   php artisan payments:apply-carry-forward --execute
 *
 *   # Filter to a specific student:
 *   php artisan payments:apply-carry-forward --execute --user-id=42
 *
 *   # Filter to a specific assessment:
 *   php artisan payments:apply-carry-forward --execute --assessment-id=17
 *
 * SAFETY GUARANTEES
 * ─────────────────
 *   1. Each carry-forward runs inside DB::transaction() — all changes commit
 *      or all roll back.
 *   2. Terms with status='processed' are skipped — already correct.
 *   3. Terms with status='underpaid' are skipped — already correctly resolved.
 *   4. AccountService::recalculate() is called after each assessment.
 */
class ApplyCarryForwardToPartialTerms extends Command
{
    protected $signature = 'payments:apply-carry-forward
                            {--dry-run : Preview changes without writing to the database}
                            {--execute : Apply changes to the database}
                            {--user-id= : Limit to a specific student (user ID)}
                            {--assessment-id= : Limit to a specific assessment ID}';

    protected $description = 'Backfill: apply carry-forward or underpaid rule to existing PARTIAL payment terms.';

    public function handle(): int
    {
        $isDryRun    = $this->option('dry-run');
        $isExecute   = $this->option('execute');
        $filterUser  = $this->option('user-id') ? (int) $this->option('user-id') : null;
        $filterAssmt = $this->option('assessment-id') ? (int) $this->option('assessment-id') : null;

        if (! $isDryRun && ! $isExecute) {
            $this->error('Specify either --dry-run or --execute.');
            return self::FAILURE;
        }

        if ($isDryRun && $isExecute) {
            $this->error('Cannot use --dry-run and --execute together.');
            return self::FAILURE;
        }

        $mode = $isDryRun ? 'DRY RUN' : 'EXECUTE';
        $this->line('');
        $this->info('══════════════════════════════════════════════════');
        $this->info("  ApplyCarryForwardToPartialTerms — {$mode}");
        $this->info('══════════════════════════════════════════════════');

        // Find all PARTIAL terms with a real balance.
        $query = StudentPaymentTerm::where('status', PaymentStatus::PARTIAL->value)
            ->where('balance', '>', 0)
            ->with('assessment.user');

        if ($filterUser) {
            $query->whereHas('assessment', fn ($q) => $q->where('user_id', $filterUser));
        }
        if ($filterAssmt) {
            $query->where('student_assessment_id', $filterAssmt);
        }

        $partialTerms = $query->orderBy('student_assessment_id')->orderBy('term_order')->get();

        if ($partialTerms->isEmpty()) {
            $this->info('No PARTIAL terms found. Nothing to do.');
            return self::SUCCESS;
        }

        $this->line('');
        $this->line("Found {$partialTerms->count()} PARTIAL term(s):");
        $this->line('');

        // Group by assessment for batch processing.
        $grouped        = $partialTerms->groupBy('student_assessment_id');
        $totalCarried   = 0;
        $totalUnderpaid = 0;
        $totalErrors    = 0;

        foreach ($grouped as $assessmentId => $terms) {
            $firstTerm  = $terms->first();
            $assessment = $firstTerm->assessment;
            $student    = $assessment?->user;

            $this->line('──────────────────────────────────────────────────');
            $this->line(sprintf(
                '  Assessment #%d | %s | %s %s %s',
                $assessmentId,
                $student?->account_id ?? '?',
                $student?->name ?? 'Unknown',
                $assessment?->year_level ?? '',
                $assessment?->semester ?? ''
            ));

            foreach ($terms as $term) {
                $carryoverCents = MoneyService::toCents($term->balance);
                $carryoverStr   = MoneyService::formatFromCents($carryoverCents);

                // Find the next term in the same assessment that has balance > 0.
                // The next term might not exist if this is the last term.
                $nextTerm = StudentPaymentTerm::where('student_assessment_id', $assessmentId)
                    ->where('term_order', '>', $term->term_order)
                    ->where('balance', '>', 0)
                    ->orderBy('term_order')
                    ->first();

                if ($nextTerm) {
                    // ── MID-TERM PATH: carry forward and close ────────────────
                    $this->line(sprintf(
                        '    CARRY-FWD  %-20s  %s balance → %s',
                        $term->term_name,
                        $carryoverStr,
                        $nextTerm->term_name
                    ));

                    if ($isExecute) {
                        try {
                            $this->executeCarryForward($term, $nextTerm, $carryoverCents);
                            $totalCarried++;
                        } catch (\Exception $e) {
                            $this->error("    ERROR on term #{$term->id}: " . $e->getMessage());
                            $totalErrors++;
                            Log::error('ApplyCarryForwardToPartialTerms carry-forward failed', [
                                'term_id'    => $term->id,
                                'term_name'  => $term->term_name,
                                'assessment' => $assessmentId,
                                'error'      => $e->getMessage(),
                            ]);
                        }
                    } else {
                        $totalCarried++;
                    }

                } else {
                    // ── LAST-TERM PATH: convert to UNDERPAID ──────────────────
                    // No next term exists. This is the final term in the assessment.
                    // The close-and-carry rule does not apply here. The student
                    // owes the remaining balance on this term and must pay it in
                    // a future transaction. Set status = 'underpaid'.
                    $this->line(sprintf(
                        '    UNDERPAID  %-20s  %s — final term, no carry target. Setting underpaid.',
                        $term->term_name,
                        $carryoverStr
                    ));

                    if ($isExecute) {
                        try {
                            $this->executeMarkUnderpaid($term, $carryoverCents);
                            $totalUnderpaid++;
                        } catch (\Exception $e) {
                            $this->error("    ERROR on term #{$term->id}: " . $e->getMessage());
                            $totalErrors++;
                            Log::error('ApplyCarryForwardToPartialTerms underpaid conversion failed', [
                                'term_id'    => $term->id,
                                'term_name'  => $term->term_name,
                                'assessment' => $assessmentId,
                                'error'      => $e->getMessage(),
                            ]);
                        }
                    } else {
                        $totalUnderpaid++;
                    }
                }
            }

            // After all partial terms in this assessment are resolved,
            // resync the account balance so the student portal is correct.
            if ($isExecute && $student) {
                AccountService::recalculate($student);
            }
        }

        // ── Summary ───────────────────────────────────────────────────────────
        $this->line('');
        $this->info('══════════════════════════════════════════════════');
        $this->info("  Summary ({$mode})");
        $this->line('');
        $this->info(sprintf('  %-30s %d', 'Carry-forward (mid-term):', $totalCarried));
        $this->info(sprintf('  %-30s %d', 'Underpaid (final term):', $totalUnderpaid));

        if ($totalErrors > 0) {
            $this->error(sprintf('  %-30s %d  ← check laravel.log', 'Errors:', $totalErrors));
        } else {
            $this->info(sprintf('  %-30s %d', 'Errors:', $totalErrors));
        }

        $this->info('══════════════════════════════════════════════════');

        if ($isDryRun) {
            $this->line('');
            $this->warn('This was a DRY RUN. No data was modified.');
            $this->warn('Run with --execute to apply changes.');
        }

        if ($isExecute) {
            $this->line('');
            if ($totalCarried > 0) {
                $this->info("  Carry-forward applied. {$totalCarried} mid-term(s) now PROCESSED.");
            }
            if ($totalUnderpaid > 0) {
                $this->info("  {$totalUnderpaid} final term(s) now UNDERPAID — balance preserved.");
            }
            $this->info('  accounts.balance resynced for all affected students.');
        }

        $this->line('');

        return $totalErrors > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Apply the carry-forward for a single mid-term PARTIAL row.
     * Runs inside DB::transaction(). Locks both rows.
     */
    private function executeCarryForward(
        StudentPaymentTerm $partialTerm,
        StudentPaymentTerm $nextTerm,
        int $carryoverCents
    ): void {
        DB::transaction(function () use ($partialTerm, $nextTerm, $carryoverCents) {
            $locked     = StudentPaymentTerm::lockForUpdate()->findOrFail($partialTerm->id);
            $lockedNext = StudentPaymentTerm::lockForUpdate()->findOrFail($nextTerm->id);

            $nextBalBefore = MoneyService::toCents($lockedNext->balance);
            $nextBalAfter  = $nextBalBefore + $carryoverCents;

            // Add the carry amount to the receiving term.
            $lockedNext->update([
                'balance'                => MoneyService::toPesos($nextBalAfter),
                'remarks'                => 'Carry-over of ' . MoneyService::formatFromCents($carryoverCents)
                                           . ' from ' . $locked->term_name . ' (backfill)',
                'carryover_from_term_id' => $locked->id,
                'carryover_amount'       => MoneyService::toPesos($carryoverCents),
            ]);

            // Close the partial term.
            $locked->update([
                'balance'   => '0.00',
                'status'    => PaymentStatus::PROCESSED->value,
                'paid_date' => null,
                'remarks'   => MoneyService::formatFromCents($carryoverCents)
                               . ' carried to ' . $nextTerm->term_name . ' (backfill)',
            ]);

            Log::info('ApplyCarryForwardToPartialTerms: carry-forward applied', [
                'from_term' => $locked->term_name,
                'to_term'   => $nextTerm->term_name,
                'carry'     => MoneyService::formatFromCents($carryoverCents),
            ]);
        });
    }

    /**
     * Convert a last-term PARTIAL row to UNDERPAID.
     * Balance is preserved. Runs inside DB::transaction(). Locks the row.
     */
    private function executeMarkUnderpaid(
        StudentPaymentTerm $partialTerm,
        int $balanceCents
    ): void {
        DB::transaction(function () use ($partialTerm, $balanceCents) {
            $locked = StudentPaymentTerm::lockForUpdate()->findOrFail($partialTerm->id);

            // Idempotency guard: if another process already converted this row.
            if ($locked->status === PaymentStatus::UNDERPAID->value) {
                Log::info('ApplyCarryForwardToPartialTerms: term already underpaid, skipping', [
                    'term_id'   => $locked->id,
                    'term_name' => $locked->term_name,
                ]);
                return;
            }

            // Keep the existing balance — do NOT write 0.00.
            $locked->update([
                // balance stays as-is
                'status'  => PaymentStatus::UNDERPAID->value,
                'remarks' => sprintf(
                    'Converted from partial → underpaid by payments:apply-carry-forward on %s. ' .
                    'This is the final term; %s remains due.',
                    now()->toDateTimeString(),
                    MoneyService::formatFromCents($balanceCents)
                ),
            ]);

            Log::info('ApplyCarryForwardToPartialTerms: marked underpaid', [
                'term_id'        => $locked->id,
                'term_name'      => $locked->term_name,
                'balance_cents'  => $balanceCents,
                'new_status'     => PaymentStatus::UNDERPAID->value,
            ]);
        });
    }
}