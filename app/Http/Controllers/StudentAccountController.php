<?php

namespace App\Http\Controllers;

use App\Enums\PaymentStatus;
use App\Models\Account;
use App\Models\Notification;
use App\Models\StudentAssessment;
use App\Models\StudentEnrollment;
use App\Models\StudentPaymentTerm;
use App\Models\Transaction;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class StudentAccountController extends Controller
{
    public function index()
    {
        $user = Auth::user();

        $account = Account::firstOrCreate(
            ['user_id' => $user->id],
            ['balance' => 0]
        );

        $assessment = StudentAssessment::where('user_id', $user->id)
            ->where('status', 'active')
            ->latest()
            ->first();

        $allAssessments = StudentAssessment::where('user_id', $user->id)
            ->where('status', 'active')
            ->with(['paymentTerms' => fn ($q) => $q->orderBy('term_order')])
            ->orderBy('school_year')
            ->get()
            ->map(function ($a) {
                // ── Use stored columns — never recalculate from live fee_settings ──
                $tuitionFee = (float) $a->tuition_fee;
                $labFee     = (float) $a->lab_fee;
                $miscFee    = (float) $a->misc_fee;

                // Entrepreneurship fee has no dedicated column.
                // Recover it: total − tuition − lab − misc.
                $entrepFee = max(0.0, round(
                    (float) $a->total_assessment - $tuitionFee - $labFee - $miscFee,
                    2
                ));

                $labAndEntrepFee = round($labFee + $entrepFee, 2);

                return [
                    'id'                   => $a->id,
                    'assessment_number'    => $a->assessment_number,
                    'year_level'           => $a->year_level,
                    'semester'             => $a->semester,
                    'school_year'          => $a->school_year,
                    'course'               => $a->course ?? null,
                    'total_assessment'     => (float) $a->total_assessment,
                    'tuition_fee'          => $tuitionFee,
                    'lab_fee'              => $labAndEntrepFee,
                    'misc_fee'             => $miscFee,
                    'other_fees'           => round($labAndEntrepFee + $miscFee, 2),
                    'lec_units'            => (float) $a->lec_units,
                    'nstp_lec_units'       => (float) ($a->nstp_lec_units ?? 0),
                    'lab_units'            => (int) $a->lab_units,
                    'lab_subjects'         => (int) $a->lab_subjects,
                    'entrepreneurship_fee' => $entrepFee,

                    'fee_breakdown' => [
                        [
                            'category' => 'Tuition',
                            'name'     => 'Tuition Fee',
                            'code'     => 'TUI',
                            'units'    => (float) $a->lec_units + (float) ($a->nstp_lec_units ?? 0),
                            'amount'   => $tuitionFee,
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
                            'amount'   => $miscFee,
                        ],
                    ],
                    'status'       => $a->status,
                    'created_at'   => $a->created_at,
                    'paymentTerms' => $a->paymentTerms->map(fn ($t) => [
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
            });

        $paymentTerms = $assessment
            ? StudentPaymentTerm::where('student_assessment_id', $assessment->id)
                ->orderBy('term_order')
                ->get()
            : collect();

        $transactions = Transaction::where('user_id', $user->id)
            ->where('kind', 'payment')
            ->orderBy('created_at', 'desc')
            ->get();

        $totalPaid = 0;
        if ($assessment) {
            $totalAssessment = (float) $assessment->total_assessment;
            $outstanding     = (float) $paymentTerms->sum('balance');
            $totalPaid       = round(max(0, $totalAssessment - $outstanding), 2);
        }

        $pendingApprovalPayments = $transactions
            ->where('status', PaymentStatus::AWAITING_APPROVAL->value)
            ->map(fn ($txn) => [
                'id'               => $txn->id,
                'reference'        => $txn->reference,
                'amount'           => (float) $txn->amount,
                'selected_term_id' => $txn->meta['selected_term_id'] ?? null,
                'term_name'        => $txn->meta['term_name'] ?? $txn->type ?? 'Payment',
                'created_at'       => $txn->created_at,
            ])
            ->values();

        // ── BUG FIX #2: Apply all required notification scopes consistently ──
        // Previously this used a raw manual query that skipped:
        //   - withinDateRange() → students saw expired notifications
        //   - forDueDateTrigger() → students saw premature trigger-based notifications
        //   - forBalance() → balance_filter was not respected
        // Now we use the same scope chain as StudentDashboardController.
        $notifications = Notification::active()
            ->forUser($user->id)
            ->withinDateRange()
            ->forDueDateTrigger($user)
            ->forBalance($user)
            ->distinct()
            ->orderByDesc('created_at')
            ->take(10)
            ->get()
            ->values();

        $assessmentTermIndex = $allAssessments->keyBy(
            fn ($a) => $a['school_year'] . '||' . $a['semester']
        );

        $enrollmentRows = StudentEnrollment::where('user_id', $user->id)
            ->where('status', 'enrolled')
            ->get(['subject_id', 'school_year', 'semester']);

        $enrolledSubjectsByAssessment = [];
        foreach ($enrollmentRows as $row) {
            $termKey = $row->school_year . '||' . $row->semester;
            if (! isset($assessmentTermIndex[$termKey])) {
                continue;
            }
            $assessmentId = $assessmentTermIndex[$termKey]['id'];
            if (! isset($enrolledSubjectsByAssessment[$assessmentId])) {
                $enrolledSubjectsByAssessment[$assessmentId] = [];
            }
            $enrolledSubjectsByAssessment[$assessmentId][] = (int) $row->subject_id;
        }

        return Inertia::render('Student/AccountOverview', [
            'account'                      => $account,
            'transactions'                 => $transactions->values(),
            'totalPaid'                    => $totalPaid,
            'fees'                         => [],
            'latestAssessment'             => $assessment ? array_merge(
                $assessment->toArray(),
                [
                    'is_irregular'   => (bool) $user->is_irregular,
                    'middle_initial' => $user->middle_initial,
                    'student_name'   => $user->name,
                ]
            ) : null,
            'allAssessments'               => $allAssessments,
            'paymentTerms'                 => $paymentTerms->values(),
            'notifications'                => $notifications,
            'pendingApprovalPayments'      => $pendingApprovalPayments,
            'enrolledSubjectsByAssessment' => $enrolledSubjectsByAssessment,
        ]);
    }
}