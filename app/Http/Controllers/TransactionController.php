<?php

namespace App\Http\Controllers;

use App\Enums\PaymentStatus;
use Barryvdh\DomPDF\Facade\Pdf;
use App\Models\AssessmentSubject;
use App\Models\Transaction;
use App\Models\User;
use App\Models\StudentPaymentTerm;
use App\Models\StudentAssessment;
use App\Models\Workflow;
use App\Enums\UserRoleEnum;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Illuminate\Support\Str;
use App\Services\AccountService;
use App\Services\WorkflowService;
use App\Services\StudentPaymentService;
use App\Events\PaymentRecorded;

class TransactionController extends Controller
{
    public function __construct(protected WorkflowService $workflowService)
    {
    }

    // ─── index ────────────────────────────────────────────────────────────────

    public function index(Request $request)
    {
        $user = $request->user();

        if (in_array($user->role->value, ['admin', 'accounting'])) {
            // ── Staff branch ─────────────────────────────────────────────────
            $transactions = Transaction::with('user')
                ->where('kind', 'payment')
                ->orderByDesc('year')
                ->orderByDesc('semester')
                ->get()
                ->map(fn ($t) => $this->shapeTransaction($t, withUser: true))
                ->groupBy(fn ($txn) => $this->getTransactionGroupKey((object) $txn));

            $currentTerm = $this->getCurrentTerm();

            return Inertia::render('Transactions/Index', [
                'transactionsByTerm'  => $transactions,
                'account'             => $user->account,
                'currentTerm'         => $currentTerm,
                // Staff do not see subject panels — empty arrays
                'allAssessments'      => [],
                'backUrl'             => route('accounting.dashboard'),
            ]);
        }

        // ── Student branch ────────────────────────────────────────────────────
        $transactions = $user->transactions()
            ->with('user')
            ->where('kind', 'payment')
            ->orderByDesc('year')
            ->orderByDesc('semester')
            ->get()
            ->map(fn ($t) => $this->shapeTransaction($t, withUser: false))
            ->groupBy(fn ($txn) => $this->getTransactionGroupKey((object) $txn));

        // ── Active assessment + current term label ─────────────────────────
        $latestAssessment = StudentAssessment::where('user_id', $user->id)
            ->where('status', 'active')
            ->latest()
            ->first();

        $currentTerm = $latestAssessment
            ? trim("{$latestAssessment->school_year} {$latestAssessment->semester}")
            : $this->getCurrentTerm();

        // ── All assessments with real subject snapshots ────────────────────
        // Load assessmentSubjects eager — one query, not N+1.
        // assessment_subjects is the authoritative, immutable billing snapshot.
        // Never reconstruct subject lists from fee_settings or curriculum presets.
        $allAssessments = StudentAssessment::where('user_id', $user->id)
            ->where('status', '!=', 'cancelled')
            ->with(['assessmentSubjects' => fn ($q) => $q->orderBy('sort_order')])
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($a) => $this->shapeAssessmentForTransactions($a))
            ->toArray();

        return Inertia::render('Transactions/Index', [
            'transactionsByTerm' => $transactions,
            'account'            => $user->account,
            'currentTerm'        => $currentTerm,
            'allAssessments'     => $allAssessments,
            'backUrl'            => route('student.dashboard'),
        ]);
    }

    // ─── create / store ───────────────────────────────────────────────────────

    public function create()
    {
        $users = User::select('id', 'first_name', 'last_name', 'middle_initial', 'email')->get();

        return Inertia::render('Transactions/Create', [
            'users' => $users,
        ]);
    }

    public function store(Request $request)
    {
        if (!in_array($request->user()->role->value, ['admin', 'accounting'])) {
            abort(403, 'Unauthorized action.');
        }

        $data = $request->validate([
            'user_id'         => 'required|exists:users,id',
            'amount'          => 'required|numeric|min:0.01',
            'type'            => 'required|in:charge,payment',
            'payment_channel' => 'nullable|string',
        ]);

        $transaction = Transaction::create([
            'user_id'         => $data['user_id'],
            'reference'       => 'SYS-' . Str::upper(Str::random(8)),
            'kind'            => $data['type'],
            'type'            => 'Manual Entry',
            'amount'          => $data['amount'],
            'status'          => $data['type'] === 'payment'
                ? PaymentStatus::PAID->value
                : PaymentStatus::PENDING->value,
            'payment_channel' => $data['payment_channel'] ?? null,
            'year'            => (string) now()->year,
            'semester'        => $this->getCurrentSemesterLabel(),
            'meta'            => [
                'description' => 'Manual entry by ' . $request->user()->name,
            ],
        ]);

        AccountService::recalculate($transaction->user);

        return redirect()->route('transactions.index')
            ->with('success', 'Transaction created successfully!');
    }

    // ─── show ─────────────────────────────────────────────────────────────────

    public function show(Transaction $transaction)
    {
        $user    = auth()->user();
        $isStaff = in_array($user->role->value, ['admin', 'accounting']);

        if (!$isStaff && $transaction->user_id !== $user->id) {
            return redirect()->route('student.dashboard')
                ->with('flash.warning', 'You do not have permission to view that transaction.');
        }

        return Inertia::render('Transactions/Show', [
            'transaction' => $transaction->load('user'),
            'account'     => $transaction->user->account,
        ]);
    }

    // ─── receipt ─────────────────────────────────────────────────────────────

    public function receipt(Request $request, Transaction $transaction)
    {
        $authUser = $request->user();
        $isStaff  = in_array($authUser->role->value, ['admin', 'accounting']);

        if (!$isStaff && $transaction->user_id !== $authUser->id) {
            abort(403, 'You do not have permission to view this receipt.');
        }

        if ($transaction->status === PaymentStatus::AWAITING_APPROVAL->value) {
            abort(403, 'Receipt is not available yet. Your payment is still awaiting accounting verification.');
        }

        if ($transaction->kind !== 'payment') {
            abort(400, 'Receipts are only available for payment transactions.');
        }

        $targetUser = $transaction->user->load('account', 'student');

        $assessmentId = $transaction->meta['assessment_id'] ?? null;

        $assessment = $assessmentId
            ? StudentAssessment::find((int) $assessmentId)
            : StudentAssessment::where('user_id', $targetUser->id)
                ->where('school_year', 'like', $transaction->year . '%')
                ->where('semester', $transaction->semester)
                ->where('status', 'active')
                ->first();

        if ($assessment) {
            $academicTerm = trim("{$assessment->school_year} {$assessment->semester}");
        } else {
            $schoolYear   = $this->formatSchoolYear($transaction->year ?? now()->year);
            $academicTerm = trim("{$schoolYear} {$transaction->semester}");
        }

        $totalAssessment = $assessment
            ? round((float) $assessment->total_assessment, 2)
            : round((float) $transaction->amount, 2);

        if ($assessment) {
            $totalPaid = (float) Transaction::where('user_id', $targetUser->id)
                ->where('kind', 'payment')
                ->where('status', PaymentStatus::PAID->value)
                ->whereJsonContains('meta->assessment_id', (int) $assessment->id)
                ->sum('amount');
        } else {
            $totalPaid = round((float) $transaction->amount, 2);
        }

        $totalPaid        = round($totalPaid, 2);
        $remainingBalance = round($totalAssessment - $totalPaid, 2);

        $pdf = Pdf::loadView('pdf.receipt', [
            'assessment'       => $assessment,
            'transactions'     => collect([$transaction]),
            'student'          => $targetUser,
            'academicTerm'     => $academicTerm,
            'totalAssessment'  => $totalAssessment,
            'totalPaid'        => $totalPaid,
            'remainingBalance' => $remainingBalance,
        ]);

        $pdf->setPaper('A4', 'portrait');

        $studentId = $targetUser->account_id ?? 'unknown';
        $ref       = str_replace(['/', ' '], '-', $transaction->reference ?? (string) $transaction->id);
        $filename  = "receipt-{$studentId}-{$ref}.pdf";

        return $pdf->download($filename);
    }

    // ─── download ─────────────────────────────────────────────────────────────

    public function download(Request $request)
    {
        $authUser = $request->user();
        $isStaff  = in_array($authUser->role->value, ['admin', 'accounting']);

        if ($isStaff && $request->filled('user_id')) {
            $targetUser = User::with('account', 'student')->findOrFail((int) $request->user_id);
        } else {
            $targetUser = $authUser->load('account', 'student');
        }

        $query = Transaction::where('user_id', $targetUser->id)
            ->with('fee')
            ->orderBy('year', 'desc')
            ->orderBy('created_at', 'desc');

        $termKey = $request->input('term');

        $termStartYear  = null;
        $termSchoolYear = null;
        $termSem        = null;

        if ($termKey && $termKey !== 'All Terms') {
            $parts   = explode(' ', $termKey, 2);
            $rawYear = $parts[0] ?? null;
            $termSem = $parts[1] ?? null;

            if ($rawYear) {
                $yearParts      = explode('-', $rawYear, 2);
                $termStartYear  = $yearParts[0];
                $termSchoolYear = $rawYear;
            }

            if ($termStartYear && $termSem) {
                $query->where('year', $termStartYear)
                      ->where('semester', $termSem);
            }
        }

        $transactions = $query->get()->filter(
            fn ($txn) => $txn->kind === 'payment' && $txn->status === PaymentStatus::PAID->value
        );

        if ($transactions->isEmpty()) {
            abort(403, 'No confirmed payments available for this term. Awaiting-approval payments cannot be downloaded yet.');
        }

        $assessmentQuery = StudentAssessment::where('user_id', $targetUser->id)
            ->where('status', 'active');

        if ($termKey && $termKey !== 'All Terms' && $termSchoolYear && $termSem) {
            $assessmentQuery->where('school_year', $termSchoolYear)
                            ->where('semester', $termSem);
        }

        $totalCharges = (float) $assessmentQuery->sum('total_assessment');
        $totalPaid    = $transactions->sum('amount');
        $netBalance   = round($totalCharges - $totalPaid, 2);

        $pdf = Pdf::loadView('pdf.transactions', [
            'transactions' => $transactions,
            'student'      => $targetUser,
            'termKey'      => $termKey ?: 'All Terms',
            'totalCharges' => $totalCharges,
            'totalPaid'    => $totalPaid,
            'netBalance'   => $netBalance,
        ]);

        $pdf->setPaper('A4', 'portrait');

        $accountId = $targetUser->account_id ?? 'unknown';
        $termSlug  = $termKey ? str_replace([' ', '/'], '-', $termKey) : 'all-terms';
        $filename  = "transactions-{$accountId}-{$termSlug}.pdf";

        return $pdf->download($filename);
    }

    // ─── payNow ───────────────────────────────────────────────────────────────

    public function payNow(Request $request)
    {
        $user      = $request->user();
        $isStudent = $user->role === UserRoleEnum::STUDENT;

        $allowedMethods = $isStudent
            ? ['gcash', 'bank_transfer', 'credit_card', 'debit_card']
            : ['cash', 'gcash', 'bank_transfer', 'credit_card', 'debit_card'];

        $data = $request->validate([
            'amount'           => 'required|numeric|min:0.01',
            'payment_method'   => ['required', 'string', Rule::in($allowedMethods)],
            'paid_at'          => 'required|date',
            'description'      => 'nullable|string|max:255',
            'selected_term_id' => 'required|exists:student_payment_terms,id',
        ]);

        try {
            $term = StudentPaymentTerm::findOrFail((int) $data['selected_term_id']);

            $termUserId = $term->assessment?->user_id;
            if (!$termUserId || (int) $termUserId !== (int) $user->id) {
                throw ValidationException::withMessages(['payment' => 'Invalid payment term selected.']);
            }

            $termBalance = round((float) $term->balance, 2);
            $paidAmount  = round((float) $data['amount'], 2);

            if ($termBalance <= 0) {
                throw ValidationException::withMessages(['payment' => 'This payment term has already been fully paid.']);
            }

            if ($isStudent) {
                $alreadyPending = Transaction::where('user_id', $user->id)
                    ->where('status', PaymentStatus::AWAITING_APPROVAL->value)
                    ->where('kind', 'payment')
                    ->whereJsonContains('meta->selected_term_id', (int) $data['selected_term_id'])
                    ->exists();

                if ($alreadyPending) {
                    throw ValidationException::withMessages(['payment' => 'A payment for this term is already awaiting approval.']);
                }
            }

            $paymentService = new StudentPaymentService();

            $assessment      = $term->assessment;
            $transactionYear = $assessment
                ? explode('-', $assessment->school_year)[0]
                : (string) now()->year;
            $transactionSem  = $assessment?->semester ?? $this->getCurrentSemesterLabel();

            if ($isStudent) {
                $transaction = Transaction::create([
                    'user_id'         => $user->id,
                    'kind'            => 'payment',
                    'type'            => 'payment_submission',
                    'amount'          => $paidAmount,
                    'status'          => PaymentStatus::PENDING->value,
                    'payment_channel' => $data['payment_method'],
                    'paid_at'         => $data['paid_at'],
                    'year'            => $transactionYear,
                    'semester'        => $transactionSem,
                    'meta'            => [
                        'payment_method'   => $data['payment_method'],
                        'description'      => $data['description'] ?? null,
                        'selected_term_id' => (int) $data['selected_term_id'],
                        'term_name'        => $term->term_name,
                        'requires_proof'   => true,
                    ],
                ]);

                return redirect()->route('payment.proof.show', $transaction->id)
                    ->with('success', 'Payment submitted. Please upload proof of payment.');
            }

            $result = $paymentService->processPayment($user, $paidAmount, [
                'payment_method'   => $data['payment_method'],
                'paid_at'          => $data['paid_at'],
                'description'      => $data['description'] ?? null,
                'selected_term_id' => (int) $data['selected_term_id'],
                'term_name'        => $term->term_name,
                'year'             => $transactionYear,
                'semester'         => $transactionSem,
            ], false);

            event(new PaymentRecorded(
                $user,
                $result['transaction_id'] ?? null,
                (float) $data['amount'],
                $result['transaction_reference'] ?? 'N/A'
            ));

            return back()->with('success', 'Payment recorded successfully!');

        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('payNow failed', [
                'user_id' => $user->id,
                'error'   => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);

            return back()->withErrors(['payment' => 'Payment processing failed: ' . $e->getMessage()]);
        }
    }

    // ─── destroy ─────────────────────────────────────────────────────────────

    public function destroy(Transaction $transaction)
    {
        $transaction->delete();

        return redirect()->route('transactions.index')
            ->with('success', 'Transaction deleted successfully.');
    }

    // ─── Private helpers ──────────────────────────────────────────────────────

    /**
     * Shape a transaction row for the frontend.
     */
    private function shapeTransaction(Transaction $t, bool $withUser): array
    {
        $row = [
            'id'              => $t->id,
            'kind'            => $t->kind,
            'type'            => $t->type ?? ucfirst($t->kind),
            'amount'          => (float) $t->amount,
            'reference'       => $t->reference,
            'or_number'       => $t->or_number ?? null,
            'status'          => $t->status,
            'year'            => $t->year,
            'semester'        => $t->semester,
            'payment_channel' => $t->payment_channel ?? ($t->meta['payment_method'] ?? null),
            'meta'            => $t->meta,
            'created_at'      => $t->created_at?->toDateTimeString(),
            'paid_at'         => $t->paid_at ?? null,
        ];

        if ($withUser) {
            $row['user'] = $t->user;
        }

        return $row;
    }

    /**
     * Shape a StudentAssessment for the Transactions/Index frontend prop.
     *
     * Key change: enrolled_subjects comes from assessment_subjects (immutable
     * billing snapshot), not from a recalculation of aggregate columns.
     * This means subject names, codes, and per-subject fees are always correct —
     * even for NSTP, irregular students, and old assessments with changed rates.
     */
    private function shapeAssessmentForTransactions(StudentAssessment $a): array
    {
        // assessmentSubjects is already eager-loaded — zero extra queries.
        $subjects = $a->assessmentSubjects->map(fn (AssessmentSubject $s) => [
            'subject_id'         => $s->subject_id,
            'code'               => $s->code,
            'name'               => $s->name,
            'lec_units'          => (float) $s->lec_units,
            'lab_units'          => (int)   $s->lab_units,
            'total_units'        => (float) $s->lec_units + (int) $s->lab_units,
            'is_nstp'            => (bool)  $s->is_nstp,
            'is_pathfit'         => (bool)  $s->is_pathfit,
            'is_billable'        => (bool)  $s->is_billable,
            'nstp_billing_units' => (float) $s->nstp_billing_units,
            'tuition_fee'        => (float) $s->tuition_fee,
            'lab_fee'            => (float) $s->lab_fee,
            'total_fee'          => (float) $s->total_fee,
        ])->values()->all();

        return [
            'id'                => $a->id,
            'school_year'       => $a->school_year,
            'semester'          => $a->semester,
            'year_level'        => $a->year_level,
            'course'            => $a->course ?? null,
            'total_assessment'  => (float) $a->total_assessment,
            'lec_units'         => (float) $a->lec_units,
            'lab_units'         => (int)   $a->lab_units,
            'tuition_fee'       => (float) $a->tuition_fee,
            'lab_fee'           => (float) $a->lab_fee,
            'misc_fee'          => (float) $a->misc_fee,
            'is_taking_nstp'    => (bool)  $a->is_taking_nstp,
            'status'            => $a->status,

            // ── Real subject snapshot ─────────────────────────────────────────
            'enrolled_subjects' => $subjects,
        ];
    }

    private function getTransactionGroupKey(object $txn): string
    {
        if (!empty($txn->year) && !empty($txn->semester)) {
            $schoolYear = $this->formatSchoolYear($txn->year);
            return "{$schoolYear} {$txn->semester}";
        }

        if (empty($txn->year) && empty($txn->semester)) {
            return $this->getCurrentTerm();
        }

        if (!empty($txn->year)) {
            $schoolYear = $this->formatSchoolYear($txn->year);
            $label      = trim("{$schoolYear} {$txn->semester}");
            return $label ?: $this->getCurrentTerm();
        }

        return $this->getCurrentTerm();
    }

    private function getCurrentTerm(): string
    {
        $schoolYear = $this->formatSchoolYear(now()->year);
        return "{$schoolYear} " . $this->getCurrentSemesterLabel();
    }

    private function formatSchoolYear(string|int $year): string
    {
        $yearNum = (int) $year;
        return "{$yearNum}-" . ($yearNum + 1);
    }

    private function getCurrentSemesterLabel(): string
    {
        $month = now()->month;
        return ($month >= 6 && $month <= 10) ? '1st Sem' : '2nd Sem';
    }
}