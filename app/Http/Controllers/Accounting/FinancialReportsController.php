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

    /**
     * Derive a human-readable payment status from balance vs total assessment.
     *
     * - 'Fully Paid' : balance <= 0
     * - 'Partial'    : 0 < balance < total_assessment
     * - 'Unpaid'     : balance >= total_assessment (or total is 0)
     */
    private function deriveStatus(float $balance, float $total): string
    {
        if ($balance <= 0) {
            return 'Fully Paid';
        }

        if ($total > 0 && $balance < $total) {
            return 'Partial';
        }

        return 'Unpaid';
    }

    // ─── Index ────────────────────────────────────────────────────────────────

    public function index(Request $request)
    {
        $schoolYear = $request->get('school_year', $this->currentAcademicYear());
        $semester   = $request->get('semester', '1st');
        $year       = (int) explode('-', $schoolYear)[0];

        // ── Summary stats ─────────────────────────────────────────────────────

        $totalAssessments = StudentAssessment::where('school_year', $schoolYear)
            ->where('semester', $semester)
            ->count();

        $totalAssessmentAmount = StudentAssessment::where('school_year', $schoolYear)
            ->where('semester', $semester)
            ->sum('total_assessment');

        $totalPaid = Transaction::where('kind', 'payment')
            ->where('status', 'paid')
            ->where('year', $year)
            ->where('semester', $semester)
            ->sum('amount');

        // Balance is the authoritative value — do NOT filter by status.
        $totalOutstanding = StudentPaymentTerm::whereHas('assessment', function ($q) use ($schoolYear, $semester) {
            $q->where('school_year', $schoolYear)
              ->where('semester', $semester);
        })
            ->where('balance', '>', 0)
            ->sum('balance');

        // ── Charts ────────────────────────────────────────────────────────────

        $byCourseSummary = StudentAssessment::where('school_year', $schoolYear)
            ->where('semester', $semester)
            ->selectRaw('course, COUNT(*) as student_count, SUM(total_assessment) as total')
            ->groupBy('course')
            ->orderBy('total', 'desc')
            ->get();

        $byMonthSummary = Transaction::where('kind', 'payment')
            ->where('status', 'paid')
            ->where('year', $year)
            ->where('semester', $semester)
            ->selectRaw('MONTH(created_at) as month, SUM(amount) as total')
            ->groupBy('month')
            ->orderBy('month')
            ->get()
            ->map(fn ($item) => [
                'month' => Carbon::createFromFormat('m', $item->month)->format('M'),
                'total' => $item->total,
            ]);

        // ── Payment method breakdown ──────────────────────────────────────────

        $paymentMethods = Transaction::where('kind', 'payment')
            ->where('status', 'paid')
            ->where('year', $year)
            ->where('semester', $semester)
            ->selectRaw("COALESCE(payment_channel, 'Unspecified') as method, COUNT(*) as count, SUM(amount) as total")
            ->groupBy('payment_channel')
            ->orderByDesc('total')
            ->get();

        // ── All assessed students ─────────────────────────────────────────────
        //
        // Shows EVERY student with an assessment for this Yr/Sem.
        // Fully paid students appear with balance ₱0.00 and status "Fully Paid".
        // Sorted by balance descending so debtors appear first.
        //
        $assessedStudents = StudentAssessment::where('school_year', $schoolYear)
            ->where('semester', $semester)
            ->with(['user', 'paymentTerms'])
            ->get()
            ->map(function ($assessment) use ($year, $semester) {
                $total   = (float) $assessment->total_assessment;
                $balance = (float) $assessment->paymentTerms
                    ->where('balance', '>', 0)
                    ->sum('balance');

                $latestRef = $assessment->user?->transactions()
                    ->where('kind', 'payment')
                    ->where('status', 'paid')
                    ->where('year', $year)
                    ->where('semester', $semester)
                    ->orderByDesc('paid_at')
                    ->value('reference');

                return [
                    'accountId'   => $assessment->user?->account_id ?? 'N/A',
                    'latestRef'   => $latestRef ?? '—',
                    'studentName' => $assessment->user?->name ?? 'Unknown Student',
                    'course'      => $assessment->course ?? $assessment->user?->course ?? 'N/A',
                    'total'       => $total,
                    'balance'     => $balance,
                    'status'      => $this->deriveStatus($balance, $total),
                ];
            })
            ->sortByDesc('balance')
            ->values();

        return Inertia::render('Accounting/FinancialReports', [
            'summary' => [
                'totalAssessments'      => $totalAssessments,
                'totalAssessmentAmount' => $totalAssessmentAmount,
                'totalPaid'             => $totalPaid,
                'totalOutstanding'      => $totalOutstanding,
            ],
            'charts' => [
                'byCourse' => $byCourseSummary,
                'byMonth'  => $byMonthSummary,
            ],
            'paymentMethods'  => $paymentMethods,
            'assessedStudents' => $assessedStudents,   // renamed from outstandingStudents
            'filters' => [
                'schoolYear' => $schoolYear,
                'semester'   => $semester,
            ],
            'schoolYears' => $this->getSchoolYears(),
            'semesters'   => $this->semesterOptions(),
        ]);
    }

    // ─── Financial Report PDF export ──────────────────────────────────────────

    public function export(Request $request)
    {
        $schoolYear = $request->get('school_year', $this->currentAcademicYear());
        $semester   = $request->get('semester', '1st');
        $year       = (int) explode('-', $schoolYear)[0];

        $totalPaid = Transaction::where('kind', 'payment')
            ->where('status', 'paid')
            ->where('year', $year)
            ->where('semester', $semester)
            ->sum('amount');

        $totalAssessmentAmount = StudentAssessment::where('school_year', $schoolYear)
            ->where('semester', $semester)
            ->sum('total_assessment');

        $totalOutstanding = StudentPaymentTerm::whereHas('assessment', function ($q) use ($schoolYear, $semester) {
            $q->where('school_year', $schoolYear)
              ->where('semester', $semester);
        })
            ->where('balance', '>', 0)
            ->sum('balance');

        $summary = [
            'totalAssessments'      => StudentAssessment::where('school_year', $schoolYear)
                ->where('semester', $semester)
                ->count(),
            'totalAssessmentAmount' => $totalAssessmentAmount,
            'totalPaid'             => $totalPaid,
            'totalOutstanding'      => $totalOutstanding,
        ];

        // All assessed students — same logic as index(), no filter on balance.
        $students = StudentAssessment::where('school_year', $schoolYear)
            ->where('semester', $semester)
            ->with(['user', 'paymentTerms'])
            ->get()
            ->map(function ($assessment) use ($year, $semester) {
                $total   = (float) $assessment->total_assessment;
                $balance = (float) $assessment->paymentTerms
                    ->where('balance', '>', 0)
                    ->sum('balance');
                $paid    = $total - $balance;

                $latestRef = $assessment->user?->transactions()
                    ->where('kind', 'payment')
                    ->where('status', 'paid')
                    ->where('year', $year)
                    ->where('semester', $semester)
                    ->orderByDesc('paid_at')
                    ->value('reference');

                return [
                    'accountId'   => $assessment->user?->account_id ?? 'N/A',
                    'latestRef'   => $latestRef ?? '—',
                    'studentName' => $assessment->user?->name ?? 'Unknown Student',
                    'course'      => $assessment->course ?? $assessment->user?->course ?? 'N/A',
                    'total'       => $total,
                    'paid'        => $paid,
                    'balance'     => $balance,
                    'status'      => $this->deriveStatus($balance, $total),
                ];
            })
            ->sortByDesc('balance')
            ->values();

        $pdf = Pdf::loadView('pdf.financial-report', [
            'schoolYear'  => $schoolYear,
            'semester'    => $semester,
            'summary'     => $summary,
            'students'    => $students,
            'generatedAt' => now(),
        ]);

        $filename = 'financial-report-'
            . $schoolYear . '-'
            . str_replace(' ', '-', $semester)
            . '.pdf';

        return $pdf->download($filename);
    }

    // ─── School Years helper ──────────────────────────────────────────────────

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

    // ─── Student Assessments PDF export ──────────────────────────────────────

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

    // ─── Payment Receipts PDF export ─────────────────────────────────────────

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