<?php

namespace App\Http\Controllers;

use App\Enums\PaymentStatus;
use App\Models\Account;
use App\Models\AssessmentSubject;
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

        // Resolve the student's current active assessment.
        // All financial data on this page is scoped to this assessment.
        $assessment = StudentAssessment::where('user_id', $user->id)
            ->where('status', 'active')
            ->latest()
            ->first();

        $allAssessments = StudentAssessment::where('user_id', $user->id)
            ->where('status', 'active')
            ->with([
                'paymentTerms'      => fn ($q) => $q->orderBy('term_order'),
                'assessmentSubjects' => fn ($q) => $q->orderBy('sort_order'),
            ])
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

                    // ── Per-subject billing snapshot ──────────────────────────────
                    // Sourced from assessment_subjects (written at assessment creation).
                    // Empty for assessments created before the snapshot feature existed.
                    'enrolled_subjects' => $a->assessmentSubjects->map(fn ($s) => [
                        'subject_id'         => $s->subject_id,
                        'code'               => $s->code,
                        'name'               => $s->name,
                        'lec_units'          => (float) $s->lec_units,
                        'lab_units'          => (int) $s->lab_units,
                        'total_units'        => (float) $s->lec_units + (int) $s->lab_units,
                        'is_nstp'            => (bool) $s->is_nstp,
                        'is_pathfit'         => (bool) $s->is_pathfit,
                        'is_billable'        => (bool) $s->is_billable,
                        'nstp_billing_units' => (float) $s->nstp_billing_units,
                        'tuition_fee'        => (float) $s->tuition_fee,
                        'lab_fee'            => (float) $s->lab_fee,
                        'total_fee'          => (float) $s->total_fee,
                    ])->values()->all(),

                    // ── Aggregate totals across all subjects in this assessment ──
                    'subject_totals' => $a->assessmentSubjects->isNotEmpty() ? [
                        'lec_units'        => round($a->assessmentSubjects->sum(fn ($s) => (float) $s->lec_units), 1),
                        'lab_units'        => $a->assessmentSubjects->sum(fn ($s) => (int) $s->lab_units),
                        'total_units'      => round($a->assessmentSubjects->sum(fn ($s) => (float) $s->lec_units + (int) $s->lab_units), 1),
                        'subject_count'    => $a->assessmentSubjects->count(),
                        'total_subject_fee'=> round($a->assessmentSubjects->sum(fn ($s) => (float) $s->total_fee), 2),
                    ] : null,
                ];
            });

        $paymentTerms = $assessment
            ? StudentPaymentTerm::where('student_assessment_id', $assessment->id)
                ->orderBy('term_order')
                ->get()
            : collect();

        // ── FIXED: Scope transactions to the current assessment only. ──────────
        //
        // Previously this query had no assessment scope, returning every payment
        // the student has ever made across all semesters and school years.
        //
        // The WHERE uses two conditions joined by OR to handle both data generations:
        //
        //   (A) meta->assessment_id match  — post-S3 records. StudentPaymentService
        //       has written assessment_id into meta since the S3 fix was applied.
        //
        //   (B) year + semester column match — pre-S3 records that were created
        //       before assessment_id was stored in meta. This is safe because the
        //       system enforces one active assessment per student per year+semester,
        //       so year+semester is an unambiguous proxy for the assessment.
        //
        // pendingApprovalPayments is derived from $transactions below, so it is
        // also automatically scoped to the current assessment as a side effect.
        // ──────────────────────────────────────────────────────────────────────
        $transactions = collect();

        if ($assessment) {
            // $assessment->school_year is "2025-2026"; transactions.year stores "2025"
            $assessmentStartYear = explode('-', $assessment->school_year)[0];

            $transactions = Transaction::where('user_id', $user->id)
                ->where('kind', 'payment')
                ->where(function ($q) use ($assessment, $assessmentStartYear) {
                    // (A) Primary: assessment_id in meta (StudentPaymentService post-S3)
                    $q->whereJsonContains('meta->assessment_id', $assessment->id)
                      // (B) Fallback: year + semester columns for pre-S3 records
                      ->orWhere(function ($inner) use ($assessment, $assessmentStartYear) {
                          $inner->where('year', $assessmentStartYear)
                                ->where('semester', $assessment->semester);
                      });
                })
                ->orderBy('created_at', 'desc')
                ->get();
        }

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

        // ── Apply all required notification scopes consistently ───────────────
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