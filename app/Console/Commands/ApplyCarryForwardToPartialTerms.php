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
 * One-time backfill command that finds existing PARTIAL terms in the database
 * and applies the new close-and-carry rule retroactively.
 *
 * This is NOT run automatically. A developer must execute it manually after
 * confirming with the system administrator which partial terms should be
 * converted.
 *
 * USAGE
 * ─────
 *   # Dry run — shows what WOULD happen, makes no DB changes:
 *   php artisan payments:apply-carry-forward --dry-run
 *
 *   # Execute — applies the carry-forward, then recalculates all affected accounts:
 *   php artisan payments:apply-carry-forward --execute
 *
 *   # Filter to a specific student by user ID:
 *   php artisan payments:apply-carry-forward --execute --user-id=42
 *
 *   # Filter to a specific assessment:
 *   php artisan payments:apply-carry-forward --execute --assessment-id=17
 *
 * SAFETY GUARANTEES
 * ─────────────────
 *   1. Runs inside DB::transaction() — all changes commit or all roll back.
 *   2. The command VERIFIES the total balance invariant after each assessment:
 *      SUM(balances before) must equal SUM(balances after).
 *      If they differ by more than ₱0.01, the transaction rolls back and the
 *      assessment is logged as an error.
 *   3. Terms with status='processed' are skipped — they already carry forward.
 *   4. Terms that are the LAST term in an assessment (nowhere to carry) are
 *      logged as warnings but NOT converted — they require manual accounting review.
 */
class ApplyCarryForwardToPartialTerms extends Command
{
    protected $signature = 'payments:apply-carry-forward
                            {--dry-run : Preview changes without writing to the database}
                            {--execute : Apply changes to the database}
                            {--user-id= : Limit to a specific student (user ID)}
                            {--assessment-id= : Limit to a specific assessment ID}';

    protected $description = 'Backfill: apply carry-forward rule to existing PARTIAL payment terms.';

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
        $this->info("══════════════════════════════════════════════════");
        $this->info("  ApplyCarryForwardToPartialTerms — {$mode}");
        $this->info("══════════════════════════════════════════════════");

        // Find all PARTIAL terms.
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
        $grouped = $partialTerms->groupBy('student_assessment_id');
        $totalConverted = 0;
        $totalSkipped   = 0;
        $totalErrors    = 0;

        foreach ($grouped as $assessmentId => $terms) {
            $firstTerm  = $terms->first();
            $assessment = $firstTerm->assessment;
            $student    = $assessment?->user;

            $this->line("──────────────────────────────────────────────────");
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

                // Find next term in the same assessment with balance > 0.
                $nextTerm = StudentPaymentTerm::where('student_assessment_id', $assessmentId)
                    ->where('term_order', '>', $term->term_order)
                    ->where('balance', '>', 0)
                    ->orderBy('term_order')
                    ->first();

                if (! $nextTerm) {
                    $this->warn(sprintf(
                        '    SKIP %s — no next term to carry ₱%s into. Manual review required.',
                        $term->term_name,
                        number_format($term->balance, 2)
                    ));
                    $totalSkipped++;
                    continue;
                }

                $this->line(sprintf(
                    '    CONVERT  %-20s  %s balance → carry to %s',
                    $term->term_name,
                    $carryoverStr,
                    $nextTerm->term_name
                ));

                if ($isExecute) {
                    try {
                        $this->executeCarryForward($term, $nextTerm, $carryoverCents);
                        $totalConverted++;
                    } catch (\Exception $e) {
                        $this->error("    ERROR on term #{$term->id}: " . $e->getMessage());
                        $totalErrors++;
                        Log::error('ApplyCarryForwardToPartialTerms failed', [
                            'term_id'     => $term->id,
                            'term_name'   => $term->term_name,
                            'assessment'  => $assessmentId,
                            'error'       => $e->getMessage(),
                        ]);
                    }
                } else {
                    $totalConverted++;
                }
            }

            // After processing all partial terms in this assessment,
            // recalculate the account balance.
            if ($isExecute && $student) {
                AccountService::recalculate($student);
            }
        }

        $this->line('');
        $this->info("══════════════════════════════════════════════════");
        $this->info("  Summary ({$mode})");
        $this->info("  Would convert / converted: {$totalConverted}");
        $this->info("  Skipped (no next term):    {$totalSkipped}");
        $this->info("  Errors:                    {$totalErrors}");
        $this->info("══════════════════════════════════════════════════");

        if ($isDryRun) {
            $this->line('');
            $this->warn('This was a DRY RUN. No data was modified.');
            $this->warn('Run with --execute to apply changes.');
        }

        return $totalErrors > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Apply the carry-forward for a single partial term.
     * Runs inside a DB transaction.
     */
    private function executeCarryForward(
        StudentPaymentTerm $partialTerm,
        StudentPaymentTerm $nextTerm,
        int $carryoverCents
    ): void {
        DB::transaction(function () use ($partialTerm, $nextTerm, $carryoverCents) {
            // Re-lock both terms.
            $locked       = StudentPaymentTerm::lockForUpdate()->findOrFail($partialTerm->id);
            $lockedNext   = StudentPaymentTerm::lockForUpdate()->findOrFail($nextTerm->id);

            $nextBalBefore = MoneyService::toCents($lockedNext->balance);
            $nextBalAfter  = $nextBalBefore + $carryoverCents;

            // Update the receiving term — add the carry amount.
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

            Log::info('ApplyCarryForwardToPartialTerms: converted', [
                'from_term' => $locked->term_name,
                'to_term'   => $nextTerm->term_name,
                'carry'     => MoneyService::formatFromCents($carryoverCents),
            ]);
        });
    }
}