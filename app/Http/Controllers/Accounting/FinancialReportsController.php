<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\StudentAssessment;
use App\Models\StudentPaymentTerm;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Barryvdh\DomPDF\Facade\Pdf;

class FinancialReportsController extends Controller
{
    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function currentAcademicYear(): string
    {
        $now   = now();
        $year  = (int) $now->format('Y');
        $month = (int) $now->format('n');

        $startYear = $month < 6 ? $year - 1 : $year;

        return $startYear . '-' . ($startYear + 1);
    }

    private function semesterOptions(): array
    {
        return ['1st', '2nd', 'Summer'];
    }

    private function getSchoolYears(): array
    {
        $years         = [];
        $year          = (int) now()->format('Y');
        $month         = (int) now()->format('n');
        $academicStart = $month < 6 ? $year - 1 : $year;

        for ($i = $academicStart - 3; $i <= $academicStart + 2; $i++) {
            $years[] = "{$i}-" . ($i + 1);
        }

        return $years;
    }

    /**
     * Previous period = SAME semester, one academic year earlier.
     *
     * Examples:
     *   1st Sem  2025-2026  →  1st Sem  2024-2025
     *   2nd Sem  2025-2026  →  2nd Sem  2024-2025
     *   Summer   2025-2026  →  Summer   2024-2025
     */
    private function previousPeriod(string $schoolYear, string $semester): array
    {
        [$startYear, $endYear] = explode('-', $schoolYear);

        return [
            'school_year' => ($startYear - 1) . '-' . ($endYear - 1),
            'semester'    => $semester,
        ];
    }

    // ─── Summary builder ──────────────────────────────────────────────────────

    private function buildSummary(string $schoolYear, string $semester, int $year): array
    {
        $totalAssessments = StudentAssessment::where('school_year', $schoolYear)
            ->where('semester', $semester)
            ->count();

        $totalAssessmentAmount = StudentAssessment::where('school_year', $schoolYear)
            ->where('semester', $semester)
            ->sum('total_assessment');

        // paid_at: when money was actually received, not when the row was created
        $totalPaid = Transaction::where('kind', 'payment')
            ->where('status', 'paid')
            ->where('year', $year)
            ->where('semester', $semester)
            ->whereNotNull('paid_at')
            ->sum('amount');

        // balance is the authoritative outstanding value — do NOT rely on status
        $totalOutstanding = StudentPaymentTerm::whereHas('assessment', function ($q) use ($schoolYear, $semester) {
            $q->where('school_year', $schoolYear)
              ->where('semester', $semester);
        })
            ->where('balance', '>', 0)
            ->sum('balance');

        return [
            'totalAssessments'      => $totalAssessments,
            'totalAssessmentAmount' => (float) $totalAssessmentAmount,
            'totalPaid'             => (float) $totalPaid,
            'totalOutstanding'      => (float) $totalOutstanding,
        ];
    }

    /**
     * Historical comparison: same semester, one academic year back.
     * Returns null if that period has zero assessments (panel is hidden).
     */
    private function buildHistoricalComparison(string $schoolYear, string $semester): ?array
    {
        $prev     = $this->previousPeriod($schoolYear, $semester);
        $prevYear = (int) explode('-', $prev['school_year'])[0];

        $count = StudentAssessment::where('school_year', $prev['school_year'])
            ->where('semester', $prev['semester'])
            ->count();

        if ($count === 0) {
            return null;
        }

        $prevSummary = $this->buildSummary($prev['school_year'], $prev['semester'], $prevYear);

        return array_merge($prevSummary, [
            'label'      => $prev['semester'] . ' Sem ' . $prev['school_year'],
            'schoolYear' => $prev['school_year'],
            'semester'   => $prev['semester'],
        ]);
    }

    // ─── Chart builders ───────────────────────────────────────────────────────

    private function buildCharts(string $schoolYear, string $semester, int $year): array
    {
        $byCourse = StudentAssessment::where('school_year', $schoolYear)
            ->where('semester', $semester)
            ->selectRaw('course, COUNT(*) as student_count, SUM(total_assessment) as total')
            ->groupBy('course')
            ->orderBy('total', 'desc')
            ->get();

        // paid_at: financial reports must reflect when payment was confirmed
        $byMonth = Transaction::where('kind', 'payment')
            ->where('status', 'paid')
            ->where('year', $year)
            ->where('semester', $semester)
            ->whereNotNull('paid_at')
            ->selectRaw('MONTH(paid_at) as month, SUM(amount) as total')
            ->groupBy('month')
            ->orderBy('month')
            ->get()
            ->map(fn ($item) => [
                'month' => Carbon::createFromFormat('m', $item->month)->format('M'),
                'total' => (float) $item->total,
            ]);

        return [
            'byCourse' => $byCourse,
            'byMonth'  => $byMonth,
        ];
    }

    private function buildPaymentMethods(int $year, string $semester): array
    {
        return Transaction::where('kind', 'payment')
            ->where('status', 'paid')
            ->where('year', $year)
            ->where('semester', $semester)
            ->whereNotNull('paid_at')
            ->selectRaw("COALESCE(payment_channel, 'Unspecified') as method, COUNT(*) as count, SUM(amount) as total")
            ->groupBy('payment_channel')
            ->orderByDesc('total')
            ->get()
            ->toArray();
    }

    // ─── Outstanding students — ALL rows, no pagination ───────────────────────

    /**
     * Returns every student with an outstanding balance for the period.
     * No pagination — callers get the full list.
     *
     * Performance notes:
     *  - whereHas filters at DB level, no PHP collection filtering
     *  - withSum computes balance totals in SQL (no paymentTerms rows loaded into PHP)
     *  - latest references fetched in a single batch query (no N+1)
     */
    private function buildOutstandingStudents(string $schoolYear, string $semester, int $year): array
    {
        $assessments = StudentAssessment::where('school_year', $schoolYear)
            ->where('semester', $semester)
            ->whereHas('paymentTerms', fn ($q) => $q->where('balance', '>', 0))
            ->with(['user:id,last_name,first_name,middle_initial,account_id,course'])
            ->withSum(
                ['paymentTerms' => fn ($q) => $q->where('balance', '>', 0)],
                'balance'
            )
            ->orderByDesc('payment_terms_sum_balance')
            ->get();

        // Single query for all latest payment references — avoids N+1
        $userIds    = $assessments->pluck('user_id')->filter()->unique()->values();
        $latestRefs = Transaction::where('kind', 'payment')
            ->where('status', 'paid')
            ->where('year', $year)
            ->where('semester', $semester)
            ->whereIn('user_id', $userIds)
            ->select('user_id', 'reference', 'paid_at')
            ->orderByDesc('paid_at')
            ->get()
            ->groupBy('user_id')
            ->map(fn ($txns) => $txns->first()->reference);

        return $assessments->map(function ($assessment) use ($latestRefs) {
            $user = $assessment->user;

            $studentName = $user
                ? trim(
                    "{$user->last_name}, {$user->first_name}"
                    . ($user->middle_initial ? " {$user->middle_initial}." : '')
                )
                : 'Unknown Student';

            return [
                'accountId'   => $user?->account_id ?? 'N/A',
                'latestRef'   => $latestRefs[$assessment->user_id] ?? '—',
                'studentName' => $studentName,
                'course'      => $assessment->course ?? $user?->course ?? 'N/A',
                'total'       => (float) $assessment->total_assessment,
                'balance'     => (float) ($assessment->payment_terms_sum_balance ?? 0),
            ];
        })->values()->toArray();
    }

    // ─── Controller actions ───────────────────────────────────────────────────

    public function index(Request $request)
    {
        $schoolYear = $request->get('school_year', $this->currentAcademicYear());
        $semester   = $request->get('semester', '1st');
        $year       = (int) explode('-', $schoolYear)[0];

        $summary              = $this->buildSummary($schoolYear, $semester, $year);
        $charts               = $this->buildCharts($schoolYear, $semester, $year);
        $paymentMethods       = $this->buildPaymentMethods($year, $semester);
        $historicalComparison = $this->buildHistoricalComparison($schoolYear, $semester);
        $outstandingStudents  = $this->buildOutstandingStudents($schoolYear, $semester, $year);

        return Inertia::render('Accounting/FinancialReports', [
            'summary'              => $summary,
            'charts'               => $charts,
            'paymentMethods'       => $paymentMethods,
            'historicalComparison' => $historicalComparison,
            'outstandingStudents'  => $outstandingStudents,
            'filters'              => [
                'schoolYear' => $schoolYear,
                'semester'   => $semester,
            ],
            'schoolYears' => $this->getSchoolYears(),
            'semesters'   => $this->semesterOptions(),
        ]);
    }

    // ─── PDF exports ──────────────────────────────────────────────────────────

    public function export(Request $request)
    {
        $schoolYear = $request->get('school_year', $this->currentAcademicYear());
        $semester   = $request->get('semester', '1st');
        $year       = (int) explode('-', $schoolYear)[0];

        $summary  = $this->buildSummary($schoolYear, $semester, $year);
        $students = $this->buildOutstandingStudents($schoolYear, $semester, $year);

        // Add paid column for the PDF table
        $students = array_map(function ($s) {
            $s['paid']   = $s['total'] - $s['balance'];
            $s['status'] = 'Pending';
            return $s;
        }, $students);

        $pdf = Pdf::loadView('pdf.financial-report', [
            'schoolYear'  => $schoolYear,
            'semester'    => $semester,
            'summary'     => $summary,
            'students'    => $students,
            'generatedAt' => now(),
        ]);

        $pdf->setPaper('A4', 'landscape');

        $filename = 'financial-report-'
            . $schoolYear . '-'
            . str_replace(' ', '-', $semester)
            . '.pdf';

        return $pdf->download($filename);
    }

    public function exportAssessments(Request $request)
    {
        $schoolYear = $request->query('school_year', $this->currentAcademicYear());
        $semester   = $request->query('semester', '1st');

        $assessments = StudentAssessment::where('school_year', $schoolYear)
            ->where('semester', $semester)
            ->with(['user', 'paymentTerms'])
            ->orderBy('id')
            ->get();

        $miscItems = \App\Models\FeeSetting::whereIn('category', ['miscellaneous', 'other'])
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get()
            ->map(fn ($s) => ['label' => $s->label, 'amount' => (float) $s->amount])
            ->all();

        $pdf = Pdf::loadView('pdf.bulk-assessments', [
            'schoolYear'  => $schoolYear,
            'semester'    => $semester,
            'assessments' => $assessments,
            'miscItems'   => $miscItems,
            'generatedAt' => now(),
        ]);

        $pdf->setPaper('A4', 'portrait');
        $filename = 'assessments-' . $schoolYear . '-' . str_replace(' ', '-', $semester) . '.pdf';

        return $pdf->download($filename);
    }

    public function exportReceipts(Request $request)
    {
        $schoolYear = $request->query('school_year', $this->currentAcademicYear());
        $semester   = $request->query('semester', '1st');
        $year       = (int) explode('-', $schoolYear)[0];

        $transactions = \App\Models\Transaction::with(['user.account', 'user.student'])
            ->where('kind', 'payment')
            ->where('status', 'paid')
            ->where('year', $year)
            ->where('semester', $semester)
            ->orderBy('paid_at', 'desc')
            ->get();

        $pdf = Pdf::loadView('pdf.bulk-receipts', [
            'schoolYear'   => $schoolYear,
            'semester'     => $semester,
            'transactions' => $transactions,
            'generatedAt'  => now(),
        ]);

        $pdf->setPaper('A4', 'portrait');
        $filename = 'receipts-' . $schoolYear . '-' . str_replace(' ', '-', $semester) . '.pdf';

        return $pdf->download($filename);
    }
}