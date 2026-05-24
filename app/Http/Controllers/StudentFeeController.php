<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\CourseUnitPreset;
use App\Models\Student;
use App\Models\Subject;
use App\Models\StudentAssessment;
use App\Models\StudentPaymentTerm;
use App\Models\Transaction;
use App\Models\User;
use App\Enums\UserRoleEnum;
use App\Enums\PaymentStatus;
use App\Services\AssessmentService;
use App\Services\AccountService;
use App\Services\DiscountService;
use App\Services\StudentPaymentService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class StudentFeeController extends Controller
{
    // ─────────────────────────────────────────────────────────────
    //  HELPERS
    // ─────────────────────────────────────────────────────────────

    private function buildStudentName(User $user): string
    {
        $mi = $user->middle_initial ? ' ' . strtoupper($user->middle_initial) . '.' : '';
        return $user->last_name . ', ' . $user->first_name . $mi;
    }

    /**
     * Advance a year level string by one step.
     *
     * Canonical values: '1st Year', '2nd Year', '3rd Year', '4th Year', '5th Year'
     * Returns the next level, or the same value if already at max / unrecognised.
     */
    public static function advanceYearLevel(string $current): string
    {
        $map = [
            '1st Year' => '2nd Year',
            '2nd Year' => '3rd Year',
            '3rd Year' => '4th Year',
            '4th Year' => '5th Year',
        ];

        return $map[$current] ?? $current;
    }

    /**
     * Return all fully-paid assessments for a student, sorted chronologically.
     *
     * "Fully paid" covers two states:
     *
     *   status = 'completed' — set by store() when a new assessment is created,
     *     after the account balance is verified to be zero. Always fully paid.
     *
     *   status = 'active' with sum(payment_terms.balance) = 0 — the student has
     *     paid their current assessment in full but no new assessment has been
     *     created yet, so store() has never flipped it to 'completed'.
     *     This is the common case: student finished paying 1st Sem and now
     *     Accounting is about to create the 2nd Sem assessment.
     */
    private function getPaidSemesters(int $userId): array
    {
        $semesterOrder = ['1st' => 1, '2nd' => 2, 'Summer' => 3];

        return StudentAssessment::where('user_id', $userId)
            ->whereIn('status', ['completed', 'active'])
            ->with('paymentTerms')
            ->select('id', 'semester', 'school_year', 'total_assessment', 'status', 'year_level')
            ->get()
            ->filter(function ($a) {
                return $a->status === 'completed'
                    || $a->paymentTerms->sum('balance') <= 0;
            })
            ->sortBy([
                fn ($a, $b) => strcmp($a->school_year, $b->school_year),
                fn ($a, $b) => ($semesterOrder[$a->semester] ?? 99) <=> ($semesterOrder[$b->semester] ?? 99),
            ])
            ->values()
            ->map(fn ($a) => [
                'semester'         => $a->semester,
                'school_year'      => $a->school_year,
                'assessment_id'    => $a->id,
                'total_assessment' => (float) $a->total_assessment,
                'year_level'       => $a->year_level,   // ← pass stored year_level for advancement calc
            ])
            ->all();
    }

    // ─────────────────────────────────────────────────────────────
    //  INDEX
    // ─────────────────────────────────────────────────────────────

    public function index(Request $request): Response
    {
        $sortField     = in_array($request->input('sort'), ['name', 'balance']) ? $request->input('sort') : 'name';
        $sortDirection = in_array($request->input('direction'), ['asc', 'desc']) ? $request->input('direction') : 'asc';

        $query = User::where('role', UserRoleEnum::STUDENT)
            ->with(['latestAssessment.paymentTerms', 'account']);

        if ($request->filled('search')) {
            $q = $request->search;
            $query->where(function ($q2) use ($q) {
                $q2->where('last_name', 'like', "%{$q}%")
                   ->orWhere('first_name', 'like', "%{$q}%")
                   ->orWhere('account_id', 'like', "%{$q}%");
            });
        }

        if ($request->filled('course')) {
            $query->where('course', $request->course);
        }
        if ($request->filled('year_level')) {
            $query->where('year_level', $request->year_level);
        }
        if ($request->filled('status')) {
            $query->whereHas('student', fn ($q) => $q->where('enrollment_status', $request->status));
        }

        if ($sortField === 'balance') {
            $query
                ->leftJoin('accounts', 'accounts.user_id', '=', 'users.id')
                ->select('users.*', DB::raw('COALESCE(accounts.balance, 0) as computed_balance'))
                ->orderBy('computed_balance', $sortDirection);
        } else {
            $query->select('users.*')
                ->orderBy('last_name', $sortDirection)
                ->orderBy('first_name', $sortDirection);
        }

        $students = $query->paginate(20)->through(fn ($u) => [
            'id'                => $u->id,
            'student_db_id'     => $u->student?->id,
            'account_id'        => $u->account_id,
            'name'              => $this->buildStudentName($u),
            'course'            => $u->course,
            'year_level'        => $u->year_level,
            'is_irregular'      => (bool) $u->is_irregular,
            'status'            => $u->student?->enrollment_status ?? 'pending',
            'remaining_balance' => max(0, (float) ($u->account?->balance ?? 0)),
            'account'           => $u->account ? ['balance' => max(0, (float) $u->account->balance)] : null,
            'latestAssessment'  => $u->latestAssessment ? [
                'id'               => $u->latestAssessment->id,
                'semester'         => $u->latestAssessment->semester,
                'total_assessment' => $u->latestAssessment->total_assessment,
                'paymentTerms'     => $u->latestAssessment->paymentTerms->map(fn ($t) => [
                    'id'         => $t->id,
                    'term_name'  => $t->term_name,
                    'term_order' => $t->term_order,
                    'amount'     => $t->amount,
                    'balance'    => max(0, (float) $t->balance),
                    'status'     => $t->status,
                    'due_date'   => $t->due_date,
                ])->values()->all(),
            ] : null,
        ]);

        $students->appends($request->only(['search', 'course', 'year_level', 'status', 'sort', 'direction']));

        $courses    = User::where('role', UserRoleEnum::STUDENT)->whereNotNull('course')->distinct()->pluck('course')->sort()->values();
        $yearLevels = User::where('role', UserRoleEnum::STUDENT)->whereNotNull('year_level')->distinct()->pluck('year_level')->sort()->values();

        return Inertia::render('StudentFees/Index', [
            'students'   => $students,
            'filters'    => $request->only(['search', 'course', 'year_level', 'status']),
            'sort'       => $sortField,
            'direction'  => $sortDirection,
            'courses'    => $courses,
            'yearLevels' => $yearLevels,
            'statuses'   => [
                'active'    => 'Active',
                'graduated' => 'Graduated',
                'suspended' => 'Suspended',
                'dropped'   => 'Dropped',
                'pending'   => 'Pending',
            ],
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    //  CREATE  (accounting creates assessment after selecting student)
    // ─────────────────────────────────────────────────────────────

    public function create(Request $request): Response
    {
        $preselectedStudent = null;

        if ($request->filled('student_id')) {
            $student = User::where('role', UserRoleEnum::STUDENT)
                ->where('id', $request->student_id)
                ->with('account')
                ->first();

            if ($student) {
                $preselectedStudent = [
                    'id'                => $student->id,
                    'name'              => $this->buildStudentName($student),
                    'account_id'        => $student->account_id,
                    'course'            => $student->course,
                    'year_level'        => $student->year_level,
                    'is_irregular'      => (bool) $student->is_irregular,
                    'remaining_balance' => max(0, (float) ($student->account?->balance ?? 0)),
                    'paid_semesters'    => $this->getPaidSemesters($student->id),
                ];
            }
        }

        $feeRates = AssessmentService::feeRatesForForm();

        return Inertia::render('StudentFees/Create', [
            'preselectedStudent' => $preselectedStudent,
            'feeRates'           => $feeRates,
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    //  STORE
    // ─────────────────────────────────────────────────────────────

    public function store(Request $request)
    {
        $validated = $request->validate([
            'user_id'             => ['required', 'exists:users,id'],
            'semester'            => ['required', 'in:1st,2nd,Summer'],
            'school_year'         => ['required', 'string', 'max:20'],
            'lec_units'           => ['required', 'numeric', 'min:0', 'max:50'],
            'lab_units'           => ['required', 'integer', 'min:0', 'max:20'],
            'nstp_lec_units'      => ['nullable', 'numeric', 'min:0', 'max:10'],
            'discount_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'term_percentages'    => ['nullable', 'array'],
            'term_percentages.*'  => ['numeric', 'min:0', 'max:100'],
            // ← Accept the derived year_level from the frontend
            'year_level'          => ['nullable', 'string', 'max:50'],
        ]);

        $validated['lec_units']           = (float) $validated['lec_units'];
        $validated['lab_units']           = (int) $validated['lab_units'];
        $validated['nstp_lec_units']      = (float) ($validated['nstp_lec_units'] ?? 0);
        $validated['discount_percentage'] = (float) ($validated['discount_percentage'] ?? 0.0);

        // Validate that term percentages add up to 100% (excluding "Upon Registration")
        if (!empty($validated['term_percentages'])) {
            $percentageTotal = array_sum($validated['term_percentages']);
            if (abs($percentageTotal - 100.0) > 0.01) {
                return back()->withErrors([
                    'term_percentages' => 'Payment term percentages must add up to 100%. Currently: ' .
                        number_format($percentageTotal, 2) . '%',
                ]);
            }
        }

        $studentAccount   = Account::where('user_id', $validated['user_id'])->first();
        $remainingBalance = max(0, (float) ($studentAccount?->balance ?? 0));

        if ($remainingBalance > 0) {
            return back()->withErrors([
                'user_id' => 'This student has a remaining balance of ₱' .
                    number_format($remainingBalance, 2) .
                    '. Please settle the outstanding balance before creating a new assessment.',
            ]);
        }

        try {
            DB::transaction(function () use ($validated) {
                $existing = StudentAssessment::where('user_id', $validated['user_id'])
                    ->where('semester', $validated['semester'])
                    ->where('school_year', $validated['school_year'])
                    ->where('status', 'active')
                    ->lockForUpdate()
                    ->first();

                if ($existing) {
                    throw new \RuntimeException(
                        'DUPLICATE_ASSESSMENT:' .
                        $validated['semester'] . ' Sem, SY ' . $validated['school_year']
                    );
                }

                StudentAssessment::where('user_id', $validated['user_id'])
                    ->where('status', 'active')
                    ->update(['status' => 'completed']);

                $rates = AssessmentService::loadRates();

                if (!empty($validated['term_percentages'])) {
                    $rates['payment_terms'] = array_map(function ($term) use ($validated) {
                        $name = $term['term_name'];
                        if (isset($validated['term_percentages'][$name])) {
                            $term['percentage'] = (float) $validated['term_percentages'][$name];
                        }
                        return $term;
                    }, $rates['payment_terms']);
                }

                $fees = AssessmentService::compute(
                    lecUnits:           $validated['lec_units'],
                    labSubjects:        $validated['lab_units'],
                    nstpLecUnits:       $validated['nstp_lec_units'],
                    discountPercentage: $validated['discount_percentage'],
                    rates:              $rates,
                );

                $student          = User::findOrFail($validated['user_id']);
                $assessmentNumber = StudentAssessment::generateAssessmentNumber();

                // Use the derived year level sent from the frontend.
                // Fall back to the student's DB year level if not provided.
                $yearLevelForAssessment = $validated['year_level'] ?? $student->year_level;

                // ─── SYNC users.year_level ────────────────────────────────
                // The frontend computes the correct year level from paid
                // semester history (advanceYearLevel). That derived value is
                // stored on the assessment, but users.year_level was never
                // updated — so the Index list and search kept showing the
                // stale (one-level-behind) value.
                // Fix: persist the advanced year level back to the user row
                // whenever it differs from the stored value.
                if ($yearLevelForAssessment && $yearLevelForAssessment !== $student->year_level) {
                    $student->year_level = $yearLevelForAssessment;
                    $student->save();
                }
                // ─────────────────────────────────────────────────────────

                $assessment = StudentAssessment::create([
                    'assessment_number'   => $assessmentNumber,
                    'user_id'             => $validated['user_id'],
                    'course'              => $student->course,
                    'semester'            => $validated['semester'],
                    'school_year'         => $validated['school_year'],
                    'lec_units'           => $validated['lec_units'],
                    'nstp_lec_units'      => $validated['nstp_lec_units'],
                    'lab_units'           => $validated['lab_units'],
                    'discount_type'       => $validated['discount_percentage'] > 0 ? 'percentage' : 'none',
                    'discount_percentage' => $validated['discount_percentage'],
                    'is_taking_nstp'      => $validated['nstp_lec_units'] > 0,
                    'tuition_fee'         => $fees['tuition_fee'],
                    'lab_fee'             => $fees['lab_fee'],
                    'misc_fee'            => $fees['misc_fee'],
                    'year_level'          => $yearLevelForAssessment,  // ← derived, not raw DB
                    'total_assessment'    => $fees['total'],
                    'status'              => 'active',
                ]);

                // Pass $fees['misc_fee'] and the tuition+lab base explicitly so
                // buildPaymentTerms() never falls through to its default calculation.
                // This is belt-and-suspenders: the service method is also fixed,
                // but explicit arguments are safer and easier to audit.
                $tuitionAndLabBase = $fees['total'] - $fees['misc_fee'];
                foreach (AssessmentService::buildPaymentTerms($fees['total'], $rates, $fees['misc_fee'], $tuitionAndLabBase) as $term) {
                    $assessment->paymentTerms()->create($term);
                }

                // FIX: Guard against duplicate charge transactions.
                $chargeYear = (int) explode('-', $validated['school_year'])[0];
                $chargeMeta = json_encode([
                    'lec_units'           => $validated['lec_units'],
                    'lab_units'           => $validated['lab_units'],
                    'nstp_lec_units'      => $validated['nstp_lec_units'],
                    'discount_percentage' => $validated['discount_percentage'],
                    'tuition_fee'         => $fees['tuition_fee'],
                    'billable_tuition'    => $fees['billable_tuition'],
                    'nstp_tuition'        => $fees['nstp_tuition'],
                    'discount_saving'     => $fees['discount_saving'],
                    'lab_fee'             => $fees['lab_fee'],
                    'misc_fee'            => $fees['misc_fee'],
                    'school_year'         => $validated['school_year'],
                    'discount_applied'    => $fees['discount_applied'],
                    'year_level'          => $yearLevelForAssessment,
                ]);
                $existingCharge = Transaction::where('user_id', $validated['user_id'])
                    ->where('kind', 'charge')
                    ->where('payment_channel', 'assessment')
                    ->where('semester', $validated['semester'])
                    ->where('year', $chargeYear)
                    ->first();
                // Use withoutEvents / saveQuietly so the Transaction::saved observer
                // does NOT fire mid-transaction (payment terms aren't rebuilt yet at
                // that point). The explicit recalculate() below is the authoritative,
                // final-state call that runs after all terms have been written.
                if ($existingCharge) {
                    $existingCharge->amount = $fees['total'];
                    $existingCharge->meta   = $chargeMeta;
                    $existingCharge->saveQuietly();
                } else {
                    Transaction::withoutEvents(fn () => Transaction::create([
                        'user_id'         => $validated['user_id'],
                        'kind'            => 'charge',
                        'status'          => 'paid',
                        'amount'          => $fees['total'],
                        'reference'       => 'ASMT-' . strtoupper(Str::random(8)),
                        'payment_channel' => 'assessment',
                        'year'            => $chargeYear,
                        'semester'        => $validated['semester'],
                        'meta'            => $chargeMeta,
                    ]));
                }

                // Single, authoritative recalculate after the full assessment
                // (assessment row + payment terms + charge transaction) is committed.
                AccountService::recalculate($student);
            });
        } catch (\RuntimeException $e) {
            if (str_starts_with($e->getMessage(), 'DUPLICATE_ASSESSMENT:')) {
                $detail = str_replace('DUPLICATE_ASSESSMENT:', '', $e->getMessage());
                return back()->withErrors([
                    'user_id' => "This student already has an active assessment for {$detail}.",
                ]);
            }
            throw $e;
        }

        return redirect()
            ->route('student-fees.show', $validated['user_id'])
            ->with('success', 'Assessment created successfully.');
    }

    // ─────────────────────────────────────────────────────────────
    //  SHOW
    // ─────────────────────────────────────────────────────────────

    public function show(int $userId): Response
    {
        $user = User::with(['latestAssessment.paymentTerms', 'account'])->findOrFail($userId);

        $allAssessmentsRaw = StudentAssessment::where('user_id', $userId)
            ->with('paymentTerms')
            ->orderByDesc('created_at')
            ->get();

        $assessment     = $user->latestAssessment;
        $tuitionPerUnit = (float) (\App\Models\FeeSetting::where('key', 'tuition_per_unit')->value('amount') ?? 364.00);

        $allAssessmentsFormatted = $allAssessmentsRaw->map(function ($a) use ($user, $tuitionPerUnit) {
            $entrepFee = max(0.0, round(
                (float) $a->total_assessment
                - (float) $a->tuition_fee
                - (float) $a->lab_fee
                - (float) $a->misc_fee,
                2
            ));

            $labAndEntrepFee = round((float) $a->lab_fee + $entrepFee, 2);

            return [
                'id'                   => $a->id,
                'course'               => $user->course,
                'semester'             => $a->semester,
                'school_year'          => $a->school_year,
                'year_level'           => $a->year_level ?? $user->year_level,
                'total_assessment'     => (float) $a->total_assessment,
                'tuition_fee'          => (float) $a->tuition_fee,
                'tuition_per_unit'     => $tuitionPerUnit,
                'lab_fee'              => $labAndEntrepFee,
                'entrepreneurship_fee' => $entrepFee,
                'misc_fee'             => (float) $a->misc_fee,
                'other_fees'           => round($labAndEntrepFee + (float) $a->misc_fee, 2),
                'lec_units'            => (float) $a->lec_units,
                'nstp_lec_units'       => (float) ($a->nstp_lec_units ?? 0),
                'lab_units'            => $a->lab_units,
                'lab_subjects'         => (int) ($a->lab_subjects ?? $a->lab_units),
                'discount_type'        => $a->discount_type,
                'is_taking_nstp'       => $a->is_taking_nstp,
                'fee_breakdown'        => [
                    [
                        'category' => 'Tuition',
                        'name'     => 'Tuition Fee',
                        'code'     => 'TUI',
                        'units'    => (float) $a->lec_units + (float) ($a->nstp_lec_units ?? 0),
                        'amount'   => (float) $a->tuition_fee,
                    ],
                    [
                        'category' => 'Laboratory',
                        'name'     => 'Laboratory Fee',
                        'code'     => 'LAB',
                        'units'    => (int) ($a->lab_subjects ?? $a->lab_units),
                        'amount'   => $labAndEntrepFee,
                    ],
                    [
                        'category' => 'Miscellaneous',
                        'name'     => 'Miscellaneous Fee',
                        'code'     => 'MISC',
                        'units'    => null,
                        'amount'   => (float) $a->misc_fee,
                    ],
                ],
                'status'       => $a->status,
                'paymentTerms' => $a->paymentTerms->sortBy('term_order')->map(fn ($t) => [
                    'id'         => $t->id,
                    'term_name'  => $t->term_name,
                    'term_order' => $t->term_order,
                    'percentage' => $t->percentage,
                    'amount'     => (float) $t->amount,
                    'balance'    => max(0, (float) $t->balance),
                    'status'     => $t->status,
                    'due_date'   => $t->due_date,
                ])->values()->all(),
            ];
        })->values()->all();

        $studentRecord = $user->student;
        $btTransactionsByAssessment = \App\Models\Transaction::where('user_id', $userId)
            ->where('kind', 'payment')
            ->where('payment_channel', 'bank_transfer')
            ->get()
            ->groupBy(fn ($t) => (string) ($t->meta['assessment_id'] ?? ''));

        $payments      = $studentRecord
            ? \App\Models\Payment::where('student_id', $studentRecord->id)
                ->with('assessment')
                ->orderByDesc('created_at')
                ->get()
                ->map(function ($p) use ($btTransactionsByAssessment) {
                    // system_reference: the system-generated reference (e.g. BT-XXXX) from the
                    // matching Transaction record. This is what should appear in the OR/Ref No.
                    // column for non-cash payments. It is DIFFERENT from meta['reference_number']
                    // which is the student's own bank slip number — that is proof of payment, not
                    // a system identifier.
                    $systemReference = null;
                    if ($p->payment_method !== 'cash') {
                        $key             = (string) $p->student_assessment_id;
                        $systemReference = ($btTransactionsByAssessment[$key] ?? collect())
                            ->map(fn ($t) => $t->reference ?? null)
                            ->filter()
                            ->first();
                    }

                    return [
                        'id'               => $p->id,
                        'assessment_id'    => $p->student_assessment_id,
                        'amount'           => (float) $p->amount,
                        'payment_method'   => $p->payment_method,
                        // or_number: only meaningful for cash payments
                        'or_number'        => $p->payment_method === 'cash' ? $p->or_number : null,
                        // system_reference: system-generated reference for bank/online payments
                        'system_reference' => $systemReference,
                        'description'      => $p->description ?? 'Payment',
                        'status'           => $p->status,
                        'paid_at'          => $p->created_at?->toDateString(),
                        'school_year'      => $p->assessment?->school_year,
                        'semester'         => $p->assessment?->semester,
                    ];
                })->all()
            : [];

        $transactions = Transaction::where('user_id', $userId)
            ->where('kind', 'payment')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($t) => [
                'id'              => $t->id,
                'kind'            => $t->kind,
                'type'            => $t->type ?? ucfirst($t->kind),
                'amount'          => (float) $t->amount,
                'reference'       => $t->reference,
                'or_number'       => $t->or_number ?? null,
                'payment_channel' => $t->payment_channel ?? ($t->meta['payment_method'] ?? null),
                'status'          => $t->status,
                'year'            => $t->year,
                'semester'        => $t->semester,
                'meta'            => $t->meta,
                'created_at'      => $t->created_at?->toDateTimeString(),
            ])->all();

        $activeEntrepFee = $assessment
            ? max(0.0, round(
                (float) $assessment->total_assessment
                - (float) $assessment->tuition_fee
                - (float) $assessment->lab_fee
                - (float) $assessment->misc_fee,
                2
            ))
            : 0.0;

        $activeLabAndEntrepFee = $assessment
            ? round((float) $assessment->lab_fee + $activeEntrepFee, 2)
            : 0.0;

        $activeAssessmentFormatted = $assessment ? [
            'id'                   => $assessment->id,
            'course'               => $user->course,
            'semester'             => $assessment->semester,
            'school_year'          => $assessment->school_year,
            'year_level'           => $assessment->year_level ?? $user->year_level,
            'lec_units'            => (float) $assessment->lec_units,
            'nstp_lec_units'       => (float) ($assessment->nstp_lec_units ?? 0),
            'lab_units'            => $assessment->lab_units,
            'lab_subjects'         => (int) ($assessment->lab_subjects ?? $assessment->lab_units),
            'total_assessment'     => (float) $assessment->total_assessment,
            'tuition_fee'          => (float) $assessment->tuition_fee,
            'lab_fee'              => $activeLabAndEntrepFee,
            'entrepreneurship_fee' => $activeEntrepFee,
            'misc_fee'             => (float) $assessment->misc_fee,
            'other_fees'           => round($activeLabAndEntrepFee + (float) $assessment->misc_fee, 2),
            'fee_breakdown'        => [
                [
                    'category' => 'Tuition',
                    'name'     => 'Tuition Fee',
                    'code'     => 'TUI',
                    'units'    => (float) $assessment->lec_units + (float) ($assessment->nstp_lec_units ?? 0),
                    'amount'   => (float) $assessment->tuition_fee,
                ],
                [
                    'category' => 'Laboratory',
                    'name'     => 'Laboratory Fee',
                    'code'     => 'LAB',
                    'units'    => (int) ($assessment->lab_subjects ?? $assessment->lab_units),
                    'amount'   => $activeLabAndEntrepFee,
                ],
                [
                    'category' => 'Miscellaneous',
                    'name'     => 'Miscellaneous Fee',
                    'code'     => 'MISC',
                    'units'    => null,
                    'amount'   => (float) $assessment->misc_fee,
                ],
            ],
            'status'               => $assessment->status,
            'tuition_per_unit'     => $tuitionPerUnit,
            'discount_type'        => $assessment->discount_type ?? 'none',
            'discount_percentage'  => (float) ($assessment->discount_percentage ?? 0),
            'paymentTerms'         => $assessment->paymentTerms->sortBy('term_order')->map(fn ($t) => [
                'id'         => $t->id,
                'term_name'  => $t->term_name,
                'term_order' => $t->term_order,
                'percentage' => $t->percentage,
                'amount'     => (float) $t->amount,
                'balance'    => max(0, (float) $t->balance),
                'status'     => $t->status,
                'due_date'   => $t->due_date,
            ])->values()->all(),
        ] : null;

        $miscItems = \App\Models\FeeSetting::whereIn('category', ['miscellaneous', 'other'])
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn ($s) => ['label' => $s->label, 'amount' => (float) $s->amount])
            ->all();

        $enrolledSubjectsByAssessment = [];
        foreach ($allAssessmentsRaw as $a) {
            $ids = \DB::table('student_enrollments')
                ->where('user_id', $userId)
                ->where('school_year', $a->school_year)
                ->where('semester', $a->semester)
                ->where('status', 'enrolled')
                ->pluck('subject_id')
                ->toArray();
            $enrolledSubjectsByAssessment[$a->id] = $ids;
        }

        return Inertia::render('StudentFees/Show', [
            'student' => [
                'id'            => $user->id,
                'student_db_id' => $user->student?->id,
                'name'          => $this->buildStudentName($user),
                'account_id'    => $user->account_id,
                'course'        => $user->course,
                'year_level'    => $user->year_level,
                'email'         => $user->email,
                'birthday'      => $user->birthday,
                'phone'         => $user->phone,
                'status'        => $user->status,
                'is_irregular'  => (bool) $user->is_irregular,
                'avatar'        => $user->avatar ?? null,
                'account'       => $user->account ? ['balance' => max(0, (float) $user->account->balance)] : null,
            ],
            'assessment'     => $activeAssessmentFormatted,
            'allAssessments' => $allAssessmentsFormatted,
            'transactions'   => $transactions,
            'payments'       => $payments,
            'feeBreakdown'   => $assessment ? [
                ['category' => 'Tuition',       'total' => (float) $assessment->tuition_fee,  'items' => 1],
                ['category' => 'Laboratory',    'total' => $activeLabAndEntrepFee,              'items' => 1],
                ['category' => 'Miscellaneous', 'total' => (float) $assessment->misc_fee,      'items' => 1],
            ] : [],
            'miscItems'                    => $miscItems,
            'backUrl'                      => route('student-fees.index'),
            'enrolledSubjectsByAssessment' => $enrolledSubjectsByAssessment,
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    //  EDIT
    // ─────────────────────────────────────────────────────────────

    public function edit(int $userId): Response|RedirectResponse
    {
        $authUser = auth()->user();
        $authRole = $authUser->role instanceof \App\Enums\UserRoleEnum
            ? $authUser->role->value
            : (string) $authUser->role;

        if (! in_array($authRole, ['admin', 'accounting'])) {
            return redirect()
                ->route('student-fees.show', $userId)
                ->with('flash.warning', 'Unauthorized to edit assessments.');
        }

        $user = User::findOrFail($userId);

        $assessment = StudentAssessment::where('user_id', $userId)
            ->where('status', 'active')
            ->with('paymentTerms')
            ->first();

        if (! $assessment) {
            return redirect()
                ->route('student-fees.show', $userId)
                ->with('flash.error', 'No active assessment found for this student. Create one first.');
        }

        $nstpUnits = 0;
        if ($assessment->is_taking_nstp) {
            $nstpUnits = \DB::table('student_enrollments')
                ->join('subjects', 'student_enrollments.subject_id', '=', 'subjects.id')
                ->where('student_enrollments.user_id', $userId)
                ->where('student_enrollments.school_year', $assessment->school_year)
                ->where('student_enrollments.semester', $assessment->semester)
                ->where('student_enrollments.status', 'enrolled')
                ->where(\DB::raw("UPPER(subjects.code)"), 'like', 'NSTP%')
                ->sum('subjects.lec_units');
        }

        $feeRates = AssessmentService::feeRatesForForm();

        return Inertia::render('StudentFees/Edit', [
            'student' => [
                'id'           => $user->id,
                'name'         => $this->buildStudentName($user),
                'account_id'   => $user->account_id,
                'course'       => $user->course,
                'year_level'   => $user->year_level,
                'is_irregular' => (bool) $user->is_irregular,
            ],
            'assessment' => [
                'id'             => $assessment->id,
                'semester'       => $assessment->semester,
                'school_year'    => $assessment->school_year,
                'lec_units'      => (float) $assessment->lec_units,
                'nstp_units'     => (float) $nstpUnits,
                'lab_units'      => $assessment->lab_units,
                'discount_type'  => $assessment->discount_type ?? 'none',
                'is_taking_nstp' => $assessment->is_taking_nstp ?? false,
            ],
            'feeRates' => $feeRates,
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    //  UPDATE
    // ─────────────────────────────────────────────────────────────

    public function update(Request $request, int $userId)
    {
        $validated = $request->validate([
            'semester'            => ['required', 'in:1st,2nd,Summer'],
            'school_year'         => ['required', 'string', 'max:20'],
            'lec_units'           => ['required', 'numeric', 'min:0', 'max:50'],
            'lab_units'           => ['required', 'integer', 'min:0', 'max:20'],
            'nstp_lec_units'      => ['nullable', 'numeric', 'min:0', 'max:10'],
            'discount_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        $validated['lec_units']           = (float) $validated['lec_units'];
        $validated['lab_units']           = (int) $validated['lab_units'];
        $validated['nstp_lec_units']      = (float) ($validated['nstp_lec_units'] ?? 0);
        $validated['discount_percentage'] = (float) ($validated['discount_percentage'] ?? 0.0);

        $assessment = StudentAssessment::where('user_id', $userId)
            ->where('status', 'active')
            ->firstOrFail();

        $paidTerms = $assessment->paymentTerms()
            ->whereNotIn('status', \App\Enums\PaymentStatus::unpaidValues())
            ->count();
        if ($paidTerms > 0) {
            return back()->withErrors([
                'lec_units' => 'Cannot edit this assessment — payments have already been recorded.',
            ]);
        }

        DB::transaction(function () use ($assessment, $validated, $userId) {
            $rates = AssessmentService::loadRates();

            $fees = AssessmentService::compute(
                lecUnits:           $validated['lec_units'],
                labSubjects:        $validated['lab_units'],
                nstpLecUnits:       $validated['nstp_lec_units'],
                discountPercentage: $validated['discount_percentage'],
                rates:              $rates,
            );

            $assessment->update([
                'semester'            => $validated['semester'],
                'school_year'         => $validated['school_year'],
                'lec_units'           => $validated['lec_units'],
                'nstp_lec_units'      => $validated['nstp_lec_units'],
                'lab_units'           => $validated['lab_units'],
                'discount_type'       => $validated['discount_percentage'] > 0 ? 'percentage' : 'none',
                'discount_percentage' => $validated['discount_percentage'],
                'is_taking_nstp'      => $validated['nstp_lec_units'] > 0,
                'tuition_fee'         => $fees['tuition_fee'],
                'lab_fee'             => $fees['lab_fee'],
                'misc_fee'            => $fees['misc_fee'],
                'total_assessment'    => $fees['total'],
            ]);

            $assessment->paymentTerms()->delete();
            // Pass $fees['misc_fee'] and the tuition+lab base explicitly.
            // See store() for the reasoning — same fix applied here.
            $tuitionAndLabBase = $fees['total'] - $fees['misc_fee'];
            foreach (AssessmentService::buildPaymentTerms($fees['total'], $rates, $fees['misc_fee'], $tuitionAndLabBase) as $term) {
                $assessment->paymentTerms()->create($term);
            }

            Transaction::where('user_id', $userId)
                ->where('kind', 'charge')
                ->where('semester', $validated['semester'])
                ->where('year', (int) explode('-', $validated['school_year'])[0])
                ->where('payment_channel', 'assessment')
                ->latest()
                ->first()
                ?->update([
                    'amount' => $fees['total'],
                    'meta'   => json_encode([
                        'lec_units'           => $validated['lec_units'],
                        'lab_units'           => $validated['lab_units'],
                        'nstp_lec_units'      => $validated['nstp_lec_units'],
                        'discount_percentage' => $validated['discount_percentage'],
                        'tuition_fee'         => $fees['tuition_fee'],
                        'billable_tuition'    => $fees['billable_tuition'],
                        'nstp_tuition'        => $fees['nstp_tuition'],
                        'discount_saving'     => $fees['discount_saving'],
                        'lab_fee'             => $fees['lab_fee'],
                        'misc_fee'            => $fees['misc_fee'],
                        'discount_applied'    => $fees['discount_applied'],
                    ]),
                ]);

            AccountService::recalculate(User::findOrFail($userId));
        });

        return redirect()
            ->route('student-fees.show', $userId)
            ->with('success', 'Assessment updated successfully.');
    }

    // ─────────────────────────────────────────────────────────────
    //  SEARCH
    // ─────────────────────────────────────────────────────────────

    public function search(Request $request): \Illuminate\Http\JsonResponse
    {
        $q = $request->get('q', '');

        $students = User::where('role', UserRoleEnum::STUDENT)
            ->where(function ($query) use ($q) {
                $query->where('last_name', 'like', "%{$q}%")
                      ->orWhere('first_name', 'like', "%{$q}%")
                      ->orWhere('account_id', 'like', "%{$q}%");
            })
            ->where('is_active', true)
            ->with('account')
            ->select('id', 'last_name', 'first_name', 'middle_initial', 'account_id', 'course', 'year_level', 'is_irregular')
            ->limit(10)
            ->get()
            ->map(function ($u) {
                return [
                    'id'                => $u->id,
                    'name'              => $this->buildStudentName($u),
                    'account_id'        => $u->account_id,
                    'course'            => $u->course,
                    'year_level'        => $u->year_level,
                    'is_irregular'      => (bool) $u->is_irregular,
                    'remaining_balance' => max(0, (float) ($u->account?->balance ?? 0)),
                    'paid_semesters'    => $this->getPaidSemesters($u->id),
                ];
            });

        return response()->json(['students' => $students]);
    }

    // ─────────────────────────────────────────────────────────────
    //  GET CURRICULUM UNITS
    // ─────────────────────────────────────────────────────────────

    public function getCurriculumUnits(Request $request): \Illuminate\Http\JsonResponse
    {
        $validated = $request->validate([
            'student_id' => 'required|exists:users,id',
            'semester'   => 'required|string',
            // ← Accept the derived year level from the frontend
            'year_level' => 'nullable|string|max:50',
        ]);

        $student = User::findOrFail($validated['student_id']);

        if ($student->is_irregular) {
            return response()->json([
                'found'        => false,
                'is_irregular' => true,
                'message'      => 'Student is irregular — units must be entered manually.',
            ]);
        }

        if (! $student->course || ! $student->year_level) {
            return response()->json([
                'found'   => false,
                'message' => 'Student has no course or year level assigned.',
            ]);
        }

        // Use the derived year level if the frontend computed one; otherwise fall
        // back to the student's stored DB value. This is what makes the Unit
        // Breakdown table pull the correct curriculum after a year-level advance.
        $effectiveYearLevel = $validated['year_level'] ?? $student->year_level;

        $semesterDb = AssessmentService::normalizeSemester($validated['semester']);

        $curriculum = AssessmentService::getCurriculumUnits(
            $student->course,
            $effectiveYearLevel,
            $validated['semester']
        );

        $preset = CourseUnitPreset::forCourseYearSem(
            $student->course,
            $effectiveYearLevel,
            $semesterDb
        );

        if (empty($curriculum['subjects'])) {
            if (! $preset) {
                return response()->json([
                    'found'   => false,
                    'message' => "No curriculum data found for {$student->course} — {$effectiveYearLevel} — {$semesterDb}. "
                            . "Add a Course Unit Preset in Fee Settings or seed subjects.",
                ]);
            }

            $nstpLecUnits = $preset->has_nstp ? AssessmentService::NSTP_MINIMUM_UNITS : 0;

            return response()->json([
                'found'              => true,
                'source'             => 'preset',
                'is_irregular'       => false,
                'billable_lec_units' => $preset->lec_units,
                'lab_subject_count'  => $preset->lab_subject_count,
                'nstp_lec_units'     => $nstpLecUnits,
                'has_nstp'           => $preset->has_nstp,
                'preset_has_nstp'    => $preset->has_nstp,
                'pathfit_units'      => 0,
                'subjects'           => [],
                'course'             => $student->course,
                'year_level'         => $effectiveYearLevel,
                'message'            => 'Units auto-filled from Course Unit Preset (no subject-level data).',
            ]);
        }

        $hasNstp      = $curriculum['has_nstp'] || ($preset?->has_nstp ?? false);
        $nstpLecUnits = $hasNstp ? AssessmentService::NSTP_MINIMUM_UNITS : 0;

        return response()->json([
            'found'              => true,
            'source'             => 'subjects',
            'is_irregular'       => false,
            'billable_lec_units' => $curriculum['billable_lec_units'],
            'lab_subject_count'  => $curriculum['lab_subject_count'],
            'nstp_lec_units'     => $nstpLecUnits,
            'has_nstp'           => $hasNstp,
            'preset_has_nstp'    => $preset?->has_nstp ?? false,
            'pathfit_units'      => $curriculum['pathfit_units'],
            'subjects'           => $curriculum['subjects'],
            'course'             => $student->course,
            'year_level'         => $effectiveYearLevel,
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    //  GET LATEST ASSESSMENT DATA
    // ─────────────────────────────────────────────────────────────

    public function getLatestAssessmentData(Request $request): \Illuminate\Http\JsonResponse
    {
        $validated = $request->validate(['student_id' => 'required|exists:users,id']);

        $latest = StudentAssessment::where('user_id', $validated['student_id'])
            ->orderByDesc('created_at')
            ->first();

        if (! $latest) {
            return response()->json(['found' => false]);
        }

        return response()->json([
            'found'        => true,
            'lec_units'    => $latest->lec_units,
            'lab_subjects' => $latest->lab_units,
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    //  EXPORT PDF
    // ─────────────────────────────────────────────────────────────

    public function exportPdf(Request $request, int $userId)
    {
        $user = User::with('account', 'student')->findOrFail($userId);

        $assessmentId = $request->query('assessment_id');

        if ($assessmentId) {
            $assessment = StudentAssessment::where('id', (int) $assessmentId)
                ->where('user_id', $userId)
                ->with('paymentTerms')
                ->firstOrFail();
        } else {
            $assessment = StudentAssessment::where('user_id', $userId)
                ->where('status', 'active')
                ->with('paymentTerms')
                ->latest()
                ->firstOrFail();
        }

        $paymentTerms = $assessment->paymentTerms()->orderBy('term_order')->get();

        $miscItems = \App\Models\FeeSetting::whereIn('category', ['miscellaneous', 'other'])
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn ($s) => ['label' => $s->label, 'amount' => (float) $s->amount])
            ->all();

        $semesterMap = [
            '1st'     => '1st Sem',
            '2nd'     => '2nd Sem',
            'Summer'  => 'Summer',
            '1st Sem' => '1st Sem',
            '2nd Sem' => '2nd Sem',
        ];
        $semesterForSubjects = $semesterMap[$assessment->semester] ?? $assessment->semester;

        $isIrregular = (bool) $user->is_irregular;

        if ($isIrregular && !empty($assessment->subjects)) {
            $subjectIds = is_array($assessment->subjects)
                ? $assessment->subjects
                : json_decode($assessment->subjects, true);

            $subjects = \DB::table('subjects')
                ->whereIn('id', $subjectIds ?? [])
                ->where('is_active', 1)
                ->orderBy('id')
                ->get();
        } else {
            $subjects = \DB::table('subjects')
                ->where('course', $assessment->course)
                ->where('year_level', $assessment->year_level)
                ->where('semester', $semesterForSubjects)
                ->where('is_active', 1)
                ->orderBy('id')
                ->get();
        }

        // ── Receipt download branch ───────────────────────────────────────────────
        // Generates a CONSOLIDATED receipt for ALL paid transactions under the
        // selected assessment — not just the latest one.
        if ($request->query('type') === 'receipt') {
            $transactions = $user->transactions()
                ->where('kind', 'payment')
                ->where('status', 'paid')
                ->whereJsonContains('meta->assessment_id', $assessment->id)
                ->orderBy('paid_at', 'asc')
                ->get();

            if ($transactions->isEmpty()) {
                abort(404, 'No paid transactions found for this assessment.');
            }

            $student = $user->load('account', 'student');

            // Build academic term label: "2028-2029, 1st Sem"
            $semLabels = [
                '1st'     => '1st Sem',
                '2nd'     => '2nd Sem',
                'Summer'  => 'Summer',
                '1st Sem' => '1st Sem',
                '2nd Sem' => '2nd Sem',
            ];
            $semesterLabel = $semLabels[$assessment->semester] ?? $assessment->semester;
            $academicTerm  = trim(($assessment->school_year ?? '') . ', ' . $semesterLabel);

            // Financial summary scoped to this assessment.
            // total_assessment: stored directly on the assessment record.
            // outstanding_balance: sum of all paymentTerms.balance (authoritative per
            //   AccountService::recalculate — never derived from transaction sums).
            // totalPaid: derived as (total − remaining) so it stays consistent
            //   with the recalculate chain even if transaction amounts diverge.
            $totalAssessment  = (float) $assessment->total_assessment;
            $remainingBalance = round((float) $assessment->outstanding_balance, 2);
            $totalPaid        = round($totalAssessment - $remainingBalance, 2);

            $receiptPdf = Pdf::loadView('pdf.receipt', [
                'transactions'     => $transactions,
                'assessment'       => $assessment,
                'student'          => $student,
                'academicTerm'     => $academicTerm,
                'totalAssessment'  => $totalAssessment,
                'totalPaid'        => $totalPaid,
                'remainingBalance' => $remainingBalance,
            ]);
            $receiptPdf->setPaper('A4', 'portrait');
            return $receiptPdf->download(
                'receipt-' . ($user->account_id ?? 'student') . '-' . ($assessment->assessment_number ?? $assessment->id) . '.pdf'
            );
        }

        // ── Lab Fee: lab_subjects × ₱1,656 + ₱600 entrepreneurship (combined) ────
        //
        // AssessmentService::compute() stores lab_fee and entrepreneurship_fee
        // separately. The PDF must show them as ONE combined Lab Fee line.
        //
        // We recompute from fee_settings (same source AssessmentService uses)
        // so the display is always accurate regardless of which creation path
        // stored the assessment — never trust the raw $assessment->lab_fee column
        // because older rows may or may not have entrep baked in.
        //
        $rates           = \App\Services\AssessmentService::loadRates();
        $labSubjectCount = (int) ($assessment->lab_subjects ?? $assessment->lab_units ?? 0);
        $labFeeRaw       = round($labSubjectCount * $rates['lab_fee_per_subject'], 2);
        $entrepFee       = $labSubjectCount > 0 ? round($rates['entrepreneurship_fee'], 2) : 0.0;
        $labFeeCombined  = round($labFeeRaw + $entrepFee, 2);
        // ─────────────────────────────────────────────────────────────────────────

        $pdf = Pdf::loadView('pdf.student-assessment', [
            'student'         => $user,
            'assessment'      => $assessment,
            'paymentTerms'    => $paymentTerms,
            'miscItems'       => $miscItems,
            'subjects'        => $subjects,
            'labFeeCombined'  => $labFeeCombined,  // lab_subjects × ₱1,656 + ₱600
        ]);

        $pdf->setPaper('A4', 'portrait');
        $filename = 'assessment-' . ($user->account_id ?? 'student') . '-' . $assessment->id . '.pdf';

        return $pdf->download($filename);
    }

    // ─────────────────────────────────────────────────────────────
    //  CREATE STUDENT
    // ─────────────────────────────────────────────────────────────

    public function createStudent(): Response
    {
        $courses    = \App\Models\Subject::distinct()->pluck('course')->sort()->values();
        $yearLevels = ['1st Year', '2nd Year', '3rd Year', '4th Year'];

        return Inertia::render('StudentFees/CreateStudent', [
            'courses'    => $courses,
            'yearLevels' => $yearLevels,
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    //  STORE STUDENT
    // ─────────────────────────────────────────────────────────────

    public function storeStudent(Request $request)
    {
        $request->validate([
            'last_name'                => 'required|string|max:255',
            'first_name'               => 'required|string|max:255',
            'middle_initial'           => 'nullable|string|max:10',
            'email'                    => 'required|string|lowercase|email|max:255|unique:' . User::class,
            'birthday'                 => 'required|date',
            'year_level'               => 'required|string|max:50',
            'course'                   => 'required|string|max:255',
            'phone'                    => 'required|string|max:20',
            'address_house_lot_unit'   => 'nullable|string|max:255',
            'address_street_name'      => 'nullable|string|max:255',
            'address_barangay'         => 'nullable|string|max:255',
            'address_municipality_city'=> 'nullable|string|max:255',
            'address_province'         => 'nullable|string|max:255',
            'is_irregular'             => 'boolean',
        ]);

        DB::beginTransaction();
        try {
            $accountId = $this->generateUniqueAccountId();

            $user = User::create([
                'last_name'                => $request->last_name,
                'first_name'               => $request->first_name,
                'middle_initial'           => $request->middle_initial,
                'email'                    => $request->email,
                'password'                 => Hash::make('password'),
                'birthday'                 => $request->birthday,
                'year_level'               => $request->year_level,
                'course'                   => $request->course,
                'phone'                    => $request->phone,
                'address_house_lot_unit'   => $request->address_house_lot_unit,
                'address_street_name'      => $request->address_street_name,
                'address_barangay'         => $request->address_barangay,
                'address_municipality_city'=> $request->address_municipality_city,
                'address_province'         => $request->address_province ?? 'Sorsogon',
                'account_id'               => $accountId,
                'status'                   => User::STATUS_ACTIVE,
                'role'                     => UserRoleEnum::STUDENT,
                'is_irregular'             => (bool) ($request->is_irregular ?? false),
            ]);

            Student::create([
                'user_id'           => $user->id,
                'student_id'        => $accountId,
                'enrollment_status' => 'active',
            ]);

            Account::create([
                'user_id'        => $user->id,
                'account_number' => $accountId,
                'balance'        => 0,
            ]);

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }

        return redirect()
            ->route('student-fees.index')
            ->with('success', 'Student account created. You can now create an assessment.');
    }

    // ─────────────────────────────────────────────────────────────
    //  EDIT STUDENT (Admin only)
    // ─────────────────────────────────────────────────────────────

    public function editStudent(Student $student): Response
    {
        $student->load('user');

        if (!$student->user) {
            abort(404, 'Student user information not found.');
        }

        $courses    = Subject::distinct()->pluck('course')->sort()->values();
        $yearLevels = ['1st Year', '2nd Year', '3rd Year', '4th Year'];

        return Inertia::render('StudentFees/EditStudent', [
            'student'    => $student,
            'courses'    => $courses,
            'yearLevels' => $yearLevels,
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    //  UPDATE STUDENT (Admin only)
    // ─────────────────────────────────────────────────────────────

    public function updateStudent(Request $request, Student $student)
    {
        $validated = $request->validate([
            'student_id'                => 'required|string|unique:students,student_id,' . $student->id,
            'first_name'                => 'required|string|max:255',
            'last_name'                 => 'required|string|max:255',
            'middle_initial'            => 'nullable|string|max:10',
            'email'                     => 'required|email|unique:users,email,' . $student->user_id,
            'course'                    => 'required|string|max:255',
            'year_level'                => 'required|string|max:50',
            'birthday'                  => 'nullable|date',
            'phone'                     => 'nullable|string|max:20',
            'address_house_lot_unit'    => 'nullable|string|max:255',
            'address_street_name'       => 'nullable|string|max:255',
            'address_barangay'          => 'nullable|string|max:255',
            'address_municipality_city' => 'nullable|string|max:255',
            'address_province'          => 'nullable|string|max:255',
        ]);

        if ($student->user) {
            $student->user->update([
                'first_name'                => $validated['first_name'],
                'last_name'                 => $validated['last_name'],
                'middle_initial'            => $validated['middle_initial'],
                'email'                     => $validated['email'],
                'course'                    => $validated['course'],
                'year_level'                => $validated['year_level'],
                'birthday'                  => $validated['birthday'],
                'phone'                     => $validated['phone'],
                'address_house_lot_unit'    => $validated['address_house_lot_unit'] ?? null,
                'address_street_name'       => $validated['address_street_name'] ?? null,
                'address_barangay'          => $validated['address_barangay'] ?? null,
                'address_municipality_city' => $validated['address_municipality_city'] ?? null,
                'address_province'          => $validated['address_province'] ?? null,
            ]);
        }

        $student->update([
            'student_id' => $validated['student_id'],
        ]);

        return redirect()
            ->route('student-fees.show', $student->user_id)
            ->with('success', 'Student information updated successfully.');
    }

    // ─────────────────────────────────────────────────────────────
    //  STORE PAYMENT
    // ─────────────────────────────────────────────────────────────

    public function storePayment(Request $request, int $userId)
    {
        $user = $request->user();

        if (! in_array($user->role->value ?? $user->role, ['accounting', 'admin'])) {
            abort(403, 'Only accounting staff can record payments.');
        }

        $student = User::findOrFail($userId);
        if (! $student->student) {
            abort(404, 'Student account not found.');
        }

        $validated = $request->validate([
            'amount'         => 'required|numeric|min:0.01',
            'payment_method' => 'required|string|in:cash,gcash,bank_transfer,credit_card,debit_card',
            'assessment_id'  => 'required|exists:student_assessments,id',
            'payment_date'   => 'required|date',
            'or_number'      => 'required|string|max:100',
        ]);

        try {
            $assessment = StudentAssessment::findOrFail((int) $validated['assessment_id']);

            if ($assessment->user_id !== $student->id) {
                abort(403, 'Assessment does not belong to this student.');
            }

            // Select the first payable term (balance > 0, not already processed).
            // PROCESSED terms have balance = 0 and are closed — skip them.
            // We filter by balance > 0 which already excludes processed terms,
            // but the explicit status exclusion makes the intent clear.
            $term = StudentPaymentTerm::where('student_assessment_id', $assessment->id)
                ->where('balance', '>', 0)
                ->whereNotIn('status', [PaymentStatus::PROCESSED->value, PaymentStatus::PAID->value])
                ->orderBy('term_order')
                ->first();

            // Fallback: if the status filter excluded something incorrectly
            // (stale status with balance > 0), use balance as the authoritative filter.
            if (! $term) {
                $term = StudentPaymentTerm::where('student_assessment_id', $assessment->id)
                    ->where('balance', '>', 0)
                    ->orderBy('term_order')
                    ->first();
            }

            if (! $term) {
                return back()->withErrors(['payment' => 'No outstanding payment terms found for this assessment. All terms may have been fully paid.']);
            }

            // ── Duplicate guard ────────────────────────────────────────────────
            // Check for a recent PAID or AWAITING_APPROVAL transaction for the same
            // starting term AND the same amount (integer-cents comparison to avoid float issues).
            // The time window is 5 minutes — not midnight-bounded — to prevent
            // accidental double-clicks during a session without midnight-reset false negatives.
            $requestCents = \App\Services\MoneyService::roundToCents($validated['amount']);

            $duplicateExists = Transaction::where('user_id', $student->id)
                ->where('kind', 'payment')
                ->whereIn('status', [PaymentStatus::PAID->value, PaymentStatus::AWAITING_APPROVAL->value])
                ->whereJsonContains('meta->selected_term_id', $term->id)
                ->where('created_at', '>=', now()->subMinutes(5))
                ->get()
                ->contains(function ($txn) use ($requestCents) {
                    // Integer-cents comparison — avoids float comparison issues.
                    return \App\Services\MoneyService::roundToCents($txn->amount) === $requestCents;
                });

            if ($duplicateExists) {
                return back()->withErrors([
                    'payment' => 'A payment of that amount for this term was already recorded in the last 5 minutes. Please verify before resubmitting.',
                ]);
            }

            $paymentService = new StudentPaymentService();
            // Use MoneyService::roundToCents for input parsing, then back to float for the service call.
            // The service converts to cents internally — this is just the entry boundary.
            $paidAmount = \App\Services\MoneyService::toFloat(\App\Services\MoneyService::roundToCents($validated['amount']));

            $paymentService->processPayment($student, $paidAmount, [
                'payment_method'   => $validated['payment_method'],
                'paid_at'          => $validated['payment_date'],
                'description'      => 'Recorded by accounting staff',
                'selected_term_id' => (int) $term->id,
                'term_name'        => $term->term_name,
                'year'             => explode('-', $assessment->school_year)[0],
                'semester'         => $assessment->semester,
                'or_number'        => $validated['or_number'],
            ], false);

            return back()->with('success', 'Payment of ₱' . number_format($paidAmount, 2) . ' recorded for ' . $this->buildStudentName($student) . '.');
        } catch (\Exception $e) {
            Log::error('storePayment failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
            return back()->withErrors(['payment' => 'Payment processing failed: ' . $e->getMessage()]);
        }
    }

    // ─────────────────────────────────────────────────────────────
    //  PRIVATE HELPERS
    // ─────────────────────────────────────────────────────────────

    private function generateUniqueAccountId(): string
    {
        do {
            $id = date('Y') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
        } while (User::where('account_id', $id)->exists());

        return $id;
    }
}