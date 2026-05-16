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
    // Statuses that represent money still owed
    private const UNPAID_STATUSES = ['unpaid', 'pending', 'partial', 'overdue'];

    // ─── Academic year helper ─────────────────────────────────────────────────
    //
    // DB shows 2026-2027 is the active year as of May 2026.
    // CCDI academic year starts June (month 6).
    // Before June → prior academic year. June onwards → current academic year.
    //
    private function currentAcademicYear(): string
    {
        $now   = now();
        $year  = (int) $now->format('Y');
        $month = (int) $now->format('n');

        // Before June → still in the previous academic year start
        $startYear = $month < 6 ? $year - 1 : $year;

        return $startYear . '-' . ($startYear + 1);
    }

    // ─── Semester values as stored in DB ─────────────────────────────────────
    //
    // DB stores '1st', '2nd', 'Summer' — NOT '1st Sem', '2nd Sem'.
    // All semester values passed to queries must use these short forms.
    //
    private function semesterOptions(): array
    {
        return ['1st', '2nd', 'Summer'];
    }

    public function index(Request $request)
    {
        $schoolYear = $request->get('school_year', $this->currentAcademicYear());
        $semester   = $request->get('semester', '1st');
        $year       = (int) explode('-', $schoolYear)[0];

        // ── Summary stats ────────────────────────────────────────────────────

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

        $totalOutstanding = StudentPaymentTerm::whereHas('assessment', function ($q) use ($schoolYear, $semester) {
            $q->where('school_year', $schoolYear)
              ->where('semester', $semester);
        })
            ->whereIn('status', self::UNPAID_STATUSES)
            ->sum('balance');

        // ── Charts ───────────────────────────────────────────────────────────

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

        // ── Payment method breakdown ─────────────────────────────────────────

        $paymentMethods = Transaction::where('kind', 'payment')
            ->where('status', 'paid')
            ->where('year', $year)
            ->where('semester', $semester)
            ->selectRaw("COALESCE(payment_channel, 'Unspecified') as method, COUNT(*) as count, SUM(amount) as total")
            ->groupBy('payment_channel')
            ->orderByDesc('total')
            ->get();

        // ── Outstanding balances table ────────────────────────────────────────

        $outstandingStudents = StudentAssessment::where('school_year', $schoolYear)
            ->where('semester', $semester)
            ->with([
                'user',
                'paymentTerms' => fn ($q) => $q->whereIn('status', self::UNPAID_STATUSES),
            ])
            ->get()
            ->map(function ($assessment) use ($year, $semester) {
                $pendingBalance = $assessment->paymentTerms->sum('balance');

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
                    'total'       => (float) $assessment->total_assessment,
                    'balance'     => (float) $pendingBalance,
                    'status'      => $pendingBalance > 0 ? 'Pending' : 'Paid',
                ];
            })
            ->filter(fn ($s) => $s['balance'] > 0)
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
            'paymentMethods'      => $paymentMethods,
            'outstandingStudents' => $outstandingStudents,
            'filters' => [
                'schoolYear' => $schoolYear,
                'semester'   => $semester,
            ],
            'schoolYears' => $this->getSchoolYears(),
            'semesters'   => $this->semesterOptions(),
        ]);
    }

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
            ->whereIn('status', self::UNPAID_STATUSES)
            ->sum('balance');

        $summary = [
            'totalAssessments'      => StudentAssessment::where('school_year', $schoolYear)
                ->where('semester', $semester)
                ->count(),
            'totalAssessmentAmount' => $totalAssessmentAmount,
            'totalPaid'             => $totalPaid,
            'totalOutstanding'      => $totalOutstanding,
        ];

        $students = StudentAssessment::where('school_year', $schoolYear)
            ->where('semester', $semester)
            ->with([
                'user',
                'paymentTerms' => fn ($q) => $q->whereIn('status', self::UNPAID_STATUSES),
            ])
            ->get()
            ->map(function ($assessment) use ($year, $semester) {
                $pendingBalance = $assessment->paymentTerms->sum('balance');
                $paid           = $assessment->total_assessment - $pendingBalance;

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
                    'total'       => (float) $assessment->total_assessment,
                    'paid'        => (float) $paid,
                    'balance'     => (float) $pendingBalance,
                    'status'      => $pendingBalance > 0 ? 'Pending' : 'Paid',
                ];
            })
            ->filter(fn ($s) => $s['balance'] > 0)
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

    private function getSchoolYears(): array
    {
        $years       = [];
        $now         = now();
        $year        = (int) $now->format('Y');
        $month       = (int) $now->format('n');

        // Anchor on actual current academic year start
        $academicStart = $month < 6 ? $year - 1 : $year;

        for ($i = $academicStart - 3; $i <= $academicStart + 2; $i++) {
            $years[] = "{$i}-" . ($i + 1);
        }

        return $years;
    }

    // ─── Export: All Student Assessments PDF ─────────────────────────────────

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

    // ─── Export: All Payment Receipts PDF ────────────────────────────────────

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