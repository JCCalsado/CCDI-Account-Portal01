<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\StudentAssessment;
use App\Models\StudentPaymentTerm;
use App\Models\Transaction;
use App\Models\User;
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
     * Derive payment status from balance vs total assessment.
     * - 'Fully Paid' : balance <= 0
     * - 'Partial'    : 0 < balance < total_assessment
     * - 'Unpaid'     : balance >= total_assessment
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

        // ── Summary ───────────────────────────────────────────────────────────

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
            $q->where('school_year', $schoolYear)->where('semester', $semester);
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

        // ── Payment methods ───────────────────────────────────────────────────

        $paymentMethods = Transaction::where('kind', 'payment')
            ->where('status', 'paid')
            ->where('year', $year)
            ->where('semester', $semester)
            ->selectRaw("COALESCE(payment_channel, 'Unspecified') as method, COUNT(*) as count, SUM(amount) as total")
            ->groupBy('payment_channel')
            ->orderByDesc('total')
            ->get();

        // ── All assessed students ─────────────────────────────────────────────
        // Every student with an assessment for this period.
        // Sorted by outstanding balance DESC (debtors first).
        // userId is passed so the Vue layer can request their transaction history.
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

                return [
                    'userId'      => $assessment->user_id,
                    'accountId'   => $assessment->user?->account_id ?? 'N/A',
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
            'paymentMethods'   => $paymentMethods,
            'assessedStudents' => $assessedStudents,
            'filters' => [
                'schoolYear' => $schoolYear,
                'semester'   => $semester,
            ],
            'schoolYears' => $this->getSchoolYears(),
            'semesters'   => $this->semesterOptions(),
            'userRole'    => auth()->user()?->role instanceof \App\Enums\UserRoleEnum
                                ? auth()->user()->role->value
                                : (string) auth()->user()?->role,
        ]);
    }

    // ─── Student Transaction History (JSON — for modal) ───────────────────────
    //
    // Returns ALL paid transactions for a student across ALL school years.
    // Called via fetch() from the Vue modal — no page load.
    //
    public function studentTransactionHistory(Request $request)
    {
        $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        $transactions = Transaction::where('user_id', $request->user_id)
            ->where('kind', 'payment')
            ->where('status', 'paid')
            ->orderBy('paid_at', 'desc')
            ->get()
            ->map(function ($txn) {
                $methodLabels = [
                    'cash'          => 'Cash',
                    'gcash'         => 'GCash',
                    'bank_transfer' => 'Bank Transfer',
                    'credit_card'   => 'Credit Card',
                    'debit_card'    => 'Debit Card',
                    'paymaya'       => 'Maya',
                    'maya'          => 'Maya',
                    'paymongo'      => 'Online Payment',
                ];
                $raw    = strtolower($txn->payment_channel ?? '');
                $method = $methodLabels[$raw]
                    ?? strtoupper(str_replace('_', ' ', $txn->payment_channel ?? ''))
                    ?: 'N/A';

                return [
                    'id'         => $txn->id,
                    'reference'  => $txn->reference ?? '—',
                    'orNumber'   => $txn->or_number ?? null,
                    'amount'     => (float) $txn->amount,
                    'method'     => $method,
                    'termName'   => $txn->meta['term_name'] ?? $txn->type ?? '—',
                    'schoolYear' => $txn->meta['school_year'] ?? ($txn->year ?? '—'),
                    'semester'   => $txn->semester ?? '—',
                    'paidAt'     => $txn->paid_at
                        ? $txn->paid_at->format('M j, Y g:i A')
                        : ($txn->created_at?->format('M j, Y g:i A') ?? '—'),
                ];
            });

        return response()->json(['transactions' => $transactions]);
    }

    // ─── Download per-student semester receipt PDF ────────────────────────────
    //
    // Generates a single-page PDF for one student covering all their payments
    // in the selected school year + semester. This is NOT the per-transaction
    // receipt from TransactionController::receipt() — this is a semester
    // account statement that accounting can hand off to students.
    //
    public function downloadStudentReceipt(Request $request)
    {
        $request->validate([
            'user_id'     => ['required', 'integer', 'exists:users,id'],
            'school_year' => ['required', 'string'],
            'semester'    => ['required', 'string'],
        ]);

        $user       = User::with(['account', 'student'])->findOrFail($request->user_id);
        $schoolYear = $request->school_year;
        $semester   = $request->semester;
        $year       = (int) explode('-', $schoolYear)[0];

        // Assessment for this period
        $assessment = StudentAssessment::where('user_id', $user->id)
            ->where('school_year', $schoolYear)
            ->where('semester', $semester)
            ->with('paymentTerms')
            ->first();

        // All paid transactions in this period
        $transactions = Transaction::where('user_id', $user->id)
            ->where('kind', 'payment')
            ->where('status', 'paid')
            ->where('year', $year)
            ->where('semester', $semester)
            ->orderBy('paid_at', 'asc')
            ->get()
            ->map(function ($txn) {
                $methodLabels = [
                    'cash'          => 'Cash',
                    'gcash'         => 'GCash',
                    'bank_transfer' => 'Bank Transfer',
                    'credit_card'   => 'Credit Card',
                    'debit_card'    => 'Debit Card',
                    'paymaya'       => 'Maya',
                    'maya'          => 'Maya',
                    'paymongo'      => 'Online Payment',
                ];
                $raw    = strtolower($txn->payment_channel ?? '');
                $method = $methodLabels[$raw]
                    ?? strtoupper(str_replace('_', ' ', $txn->payment_channel ?? ''))
                    ?: 'N/A';

                return (object) [
                    'reference'  => $txn->reference ?? '—',
                    'or_number'  => $txn->or_number,
                    'amount'     => (float) $txn->amount,
                    'method'     => $method,
                    'term_name'  => $txn->meta['term_name'] ?? $txn->type ?? '—',
                    'paid_at'    => $txn->paid_at
                        ? $txn->paid_at->format('M j, Y g:i A')
                        : ($txn->created_at?->format('M j, Y g:i A') ?? '—'),
                ];
            });

        $totalPaid    = $transactions->sum('amount');
        $totalBalance = $assessment
            ? (float) $assessment->paymentTerms->where('balance', '>', 0)->sum('balance')
            : 0.0;
        $totalAmount  = $assessment ? (float) $assessment->total_assessment : 0.0;
        $status       = $this->deriveStatus($totalBalance, $totalAmount);

        $pdf = Pdf::loadView('pdf.student-receipt', [
            'student'      => $user,
            'assessment'   => $assessment,
            'transactions' => $transactions,
            'totalPaid'    => $totalPaid,
            'totalBalance' => $totalBalance,
            'totalAmount'  => $totalAmount,
            'status'       => $status,
            'schoolYear'   => $schoolYear,
            'semester'     => $semester,
            'generatedAt'  => now(),
        ]);

        $pdf->setPaper('A4', 'portrait');

        $accountId = $user->account_id ?? 'unknown';
        $semSlug   = str_replace([' ', '/'], '-', $semester);
        $filename  = "receipt-{$accountId}-{$schoolYear}-{$semSlug}.pdf";

        return $pdf->download($filename);
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
            $q->where('school_year', $schoolYear)->where('semester', $semester);
        })
            ->where('balance', '>', 0)
            ->sum('balance');

        $summary = [
            'totalAssessments'      => StudentAssessment::where('school_year', $schoolYear)
                ->where('semester', $semester)->count(),
            'totalAssessmentAmount' => $totalAssessmentAmount,
            'totalPaid'             => $totalPaid,
            'totalOutstanding'      => $totalOutstanding,
        ];

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

                return [
                    'accountId'   => $assessment->user?->account_id ?? 'N/A',
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


    // ─── Full Academic Year PDF export ────────────────────────────────────────
    //
    // Aggregates ALL semesters within a school_year into one PDF.
    // Each student row shows per-semester breakdown + year totals.
    //
    public function exportYearly(Request $request)
    {
        $schoolYear = $request->get('school_year', $this->currentAcademicYear());
        $startYear  = (int) explode('-', $schoolYear)[0];

        // ── Collect all semesters present in the data for this school year ──
        $semesters = StudentAssessment::where('school_year', $schoolYear)
            ->distinct()
            ->pluck('semester')
            ->sortBy(fn ($s) => match ($s) { '1st' => 1, '2nd' => 2, 'Summer' => 3, default => 4 })
            ->values()
            ->all();

        if (empty($semesters)) {
            return response()->json(['error' => 'No assessments found for ' . $schoolYear], 404);
        }

        // ── Year-level summary totals ──────────────────────────────────────
        $yearTotalAssessed = StudentAssessment::where('school_year', $schoolYear)
            ->sum('total_assessment');

        $yearTotalPaid = Transaction::where('kind', 'payment')
            ->where('status', 'paid')
            ->where('year', $startYear)
            ->sum('amount');

        $yearTotalOutstanding = StudentPaymentTerm::whereHas('assessment', function ($q) use ($schoolYear) {
            $q->where('school_year', $schoolYear);
        })->where('balance', '>', 0)->sum('balance');

        $yearStudentCount = StudentAssessment::where('school_year', $schoolYear)
            ->distinct('user_id')
            ->count('user_id');

        $summary = [
            'schoolYear'       => $schoolYear,
            'studentCount'     => $yearStudentCount,
            'totalAssessed'    => (float) $yearTotalAssessed,
            'totalPaid'        => (float) $yearTotalPaid,
            'totalOutstanding' => (float) $yearTotalOutstanding,
            'semesters'        => $semesters,
        ];

        // ── Per-semester summary row (for summary cards in PDF) ───────────
        $semesterSummaries = [];
        foreach ($semesters as $sem) {
            $semYear = $startYear;
            $semesterSummaries[$sem] = [
                'assessed'    => (float) StudentAssessment::where('school_year', $schoolYear)->where('semester', $sem)->sum('total_assessment'),
                'paid'        => (float) Transaction::where('kind', 'payment')->where('status', 'paid')->where('year', $semYear)->where('semester', $sem)->sum('amount'),
                'outstanding' => (float) StudentPaymentTerm::whereHas('assessment', fn ($q) => $q->where('school_year', $schoolYear)->where('semester', $sem))->where('balance', '>', 0)->sum('balance'),
                'count'       => StudentAssessment::where('school_year', $schoolYear)->where('semester', $sem)->count(),
            ];
        }

        // ── All assessments for this school year, grouped by user ─────────
        $allAssessments = StudentAssessment::where('school_year', $schoolYear)
            ->with(['user', 'paymentTerms'])
            ->get();

        // Group by user_id, build one row per student with per-semester columns
        $studentMap = [];
        foreach ($allAssessments as $assessment) {
            $uid = $assessment->user_id;

            if (!isset($studentMap[$uid])) {
                $studentMap[$uid] = [
                    'accountId'   => $assessment->user?->account_id ?? 'N/A',
                    'studentName' => $assessment->user?->name ?? 'Unknown Student',
                    'course'      => $assessment->course ?? $assessment->user?->course ?? 'N/A',
                    'semesters'   => [],
                    'yearTotal'   => 0.0,
                    'yearPaid'    => 0.0,
                    'yearBalance' => 0.0,
                ];
            }

            $total   = (float) $assessment->total_assessment;
            $balance = (float) $assessment->paymentTerms->where('balance', '>', 0)->sum('balance');
            $paid    = $total - $balance;

            $studentMap[$uid]['semesters'][$assessment->semester] = [
                'total'   => $total,
                'paid'    => $paid,
                'balance' => $balance,
                'status'  => $this->deriveStatus($balance, $total),
            ];

            $studentMap[$uid]['yearTotal']   += $total;
            $studentMap[$uid]['yearPaid']    += $paid;
            $studentMap[$uid]['yearBalance'] += $balance;
        }

        // Derive overall year status per student, sort by balance desc
        $students = collect($studentMap)
            ->map(function ($s) {
                $s['yearStatus'] = $this->deriveStatus($s['yearBalance'], $s['yearTotal']);
                return $s;
            })
            ->sortByDesc('yearBalance')
            ->values()
            ->all();

        $pdf = Pdf::loadView('pdf.financial-report-yearly', [
            'schoolYear'       => $schoolYear,
            'summary'          => $summary,
            'semesterSummaries'=> $semesterSummaries,
            'semesters'        => $semesters,
            'students'         => $students,
            'generatedAt'      => now(),
        ])->setPaper('a4', 'landscape');

        $filename = 'financial-report-' . str_replace('/', '-', $schoolYear) . '-full-year.pdf';

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