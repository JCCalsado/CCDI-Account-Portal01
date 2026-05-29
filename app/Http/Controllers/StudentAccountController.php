<?php

namespace App\Http\Controllers;

use App\Enums\PaymentStatus;
use App\Models\Account;
use App\Models\AssessmentSubject;
use App\Models\Notification;
use App\Models\StudentAssessment;
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

        // ── Active assessment ─────────────────────────────────────────────────
        $assessment = StudentAssessment::where('user_id', $user->id)
            ->where('status', 'active')
            ->latest()
            ->first();

        // ── All assessments (for multi-semester history + subject panels) ─────
        // Eager-load assessmentSubjects + paymentTerms in one query each.
        // assessmentSubjects is the authoritative, immutable billing snapshot —
        // never reconstruct subject lists from fee_settings or presets.
        $allAssessments = StudentAssessment::where('user_id', $user->id)
            ->where('status', 'active')
            ->with([
                'paymentTerms' => fn ($q) => $q->orderBy('term_order'),
                'assessmentSubjects' => fn ($q) => $q->orderBy('sort_order'),
            ])
            ->orderBy('school_year')
            ->get()
            ->map(fn ($a) => $this->shapeAssessment($a))
            ->values();

        // ── Payment terms for the latest assessment ────────────────────────────
        $paymentTerms = $assessment
            ? StudentPaymentTerm::where('student_assessment_id', $assessment->id)
                ->orderBy('term_order')
                ->get()
            : collect();

        // ── Transactions scoped to the current assessment ─────────────────────
        // (A) meta->assessment_id  — set by StudentPaymentService post-S3
        // (B) year + semester      — fallback for pre-S3 records
        $transactions = collect();

        if ($assessment) {
            $assessmentStartYear = explode('-', $assessment->school_year)[0];

            $transactions = Transaction::where('user_id', $user->id)
                ->where('kind', 'payment')
                ->where(function ($q) use ($assessment, $assessmentStartYear) {
                    $q->whereJsonContains('meta->assessment_id', $assessment->id)
                      ->orWhere(function ($inner) use ($assessment, $assessmentStartYear) {
                          $inner->where('year', $assessmentStartYear)
                                ->where('semester', $assessment->semester);
                      });
                })
                ->orderBy('created_at', 'desc')
                ->get();
        }

        // ── Financial totals ──────────────────────────────────────────────────
        $totalPaid = 0;
        if ($assessment) {
            $totalAssessment = (float) $assessment->total_assessment;
            $outstanding     = (float) $paymentTerms->sum('balance');
            $totalPaid       = round(max(0, $totalAssessment - $outstanding), 2);
        }

        // ── Pending approval payments ─────────────────────────────────────────
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

        // ── Notifications ─────────────────────────────────────────────────────
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

        return Inertia::render('Student/AccountOverview', [
            'account'                 => $account,
            'transactions'            => $transactions->values(),
            'totalPaid'               => $totalPaid,
            'fees'                    => [],
            'latestAssessment'        => $assessment ? array_merge(
                $assessment->toArray(),
                [
                    'is_irregular'   => (bool) $user->is_irregular,
                    'middle_initial' => $user->middle_initial,
                    'student_name'   => $user->name,
                ]
            ) : null,
            'allAssessments'          => $allAssessments,
            'paymentTerms'            => $paymentTerms->values(),
            'notifications'           => $notifications,
            'pendingApprovalPayments' => $pendingApprovalPayments,
        ]);
    }

    // ─── Private helpers ──────────────────────────────────────────────────────

    /**
     * Shape a StudentAssessment into the frontend prop structure.
     *
     * Key change from old implementation:
     *   - `enrolled_subjects` is now populated from `assessment_subjects`
     *     (immutable billing snapshot) instead of being reconstructed from
     *     aggregate columns. This means:
     *       ✅ Each subject has code, name, lec/lab units, fees
     *       ✅ NSTP shows correctly with nstp_billing_units
     *       ✅ Irregular students get an empty array (correct)
     *       ✅ Historical accuracy: rates locked at assessment creation time
     *
     *   - `fee_breakdown` still carries the 3-row aggregate (Tuition / Lab /
     *     Misc) for the summary cards — unchanged from old behaviour.
     */
    private function shapeAssessment(StudentAssessment $a): array
    {
        $tuitionFee = (float) $a->tuition_fee;
        $labFee     = (float) $a->lab_fee;
        $miscFee    = (float) $a->misc_fee;

        // Entrepreneurship fee has no dedicated column — recover it from total.
        $entrepFee       = max(0.0, round((float) $a->total_assessment - $tuitionFee - $labFee - $miscFee, 2));
        $labAndEntrepFee = round($labFee + $entrepFee, 2);

        // ── Subject snapshot from assessment_subjects ─────────────────────────
        // assessmentSubjects is already eager-loaded; no extra query.
        $subjects = $a->assessmentSubjects->map(fn (AssessmentSubject $s) => [
            'subject_id'          => $s->subject_id,
            'code'                => $s->code,
            'name'                => $s->name,
            'lec_units'           => (float) $s->lec_units,
            'lab_units'           => (int)   $s->lab_units,
            'total_units'         => (float) $s->lec_units + (int) $s->lab_units,
            'is_nstp'             => (bool)  $s->is_nstp,
            'is_pathfit'          => (bool)  $s->is_pathfit,
            'is_billable'         => (bool)  $s->is_billable,
            'nstp_billing_units'  => (float) $s->nstp_billing_units,
            'tuition_fee'         => (float) $s->tuition_fee,
            'lab_fee'             => (float) $s->lab_fee,
            'total_fee'           => (float) $s->total_fee,
        ])->values()->all();

        // ── Unit totals derived from the snapshot ─────────────────────────────
        $totalLecUnits  = collect($subjects)->sum('lec_units');
        $totalLabUnits  = collect($subjects)->sum('lab_units');
        $totalSubjectFee = collect($subjects)->sum('total_fee');

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
            'entrepreneurship_fee' => $entrepFee,
            'is_taking_nstp'       => (bool) $a->is_taking_nstp,
            'discount_type'        => $a->discount_type,
            'discount_percentage'  => (float) ($a->discount_percentage ?? 0),
            'discount_name'        => $a->discount_name,
            'status'               => $a->status,
            'created_at'           => $a->created_at,

            // ── NEW: immutable per-subject billing snapshot ───────────────────
            'enrolled_subjects'    => $subjects,

            // ── Subject unit/fee totals (derived, for summary card) ───────────
            'subject_totals' => [
                'lec_units'        => $totalLecUnits,
                'lab_units'        => $totalLabUnits,
                'total_units'      => $totalLecUnits + $totalLabUnits,
                'subject_count'    => count($subjects),
                'total_subject_fee'=> $totalSubjectFee,
            ],

            // ── Aggregate fee breakdown (3-row, for the summary table) ────────
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
                    'units'    => (int) ($a->lab_units ?? 0),
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

            // ── Payment terms (for term schedule display) ─────────────────────
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
    }
}