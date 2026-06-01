<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use App\Models\PaymentReminder;
use App\Models\StudentAssessment;
use App\Models\StudentPaymentTerm;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class StudentDashboardController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        // ── Account ───────────────────────────────────────────────────────────
        $account = $user->account()->firstOrCreate(
            ['user_id' => $user->id],
            ['balance' => 0]
        );

        // ── Latest assessment + payment terms ─────────────────────────────────
        $latestAssessment = StudentAssessment::where('user_id', $user->id)
            ->with(['paymentTerms' => fn ($q) => $q->orderBy('term_order')])
            ->latest('created_at')
            ->first();

        $paymentTerms     = collect();
        $remainingBalance = 0;

        if ($latestAssessment) {
            $paymentTerms     = $latestAssessment->paymentTerms;
            $remainingBalance = $paymentTerms->sum('balance');
        }

        // ── Financial aggregates ─────────────────────────────────────────────
        // Scope total_paid to the CURRENT assessment only.
        // We join through student_payment_terms to pin paid transactions
        // to the active assessment — not all-time history.
        $totalPayments = 0;

        if ($latestAssessment) {
            // Sum all paid transactions for this assessment.
            // Scope to transactions created on or after the assessment was created.
            $totalPayments = $user->transactions()
                ->where('kind', 'payment')
                ->where('status', 'paid')
                ->where('created_at', '>=', $latestAssessment->created_at)
                ->sum('amount');
        }

        // Fallback: if no payment terms loaded, sum all active term balances directly.
        if ($paymentTerms->isEmpty()) {
            $remainingBalance = (float) StudentPaymentTerm::whereHas(
                'assessment',
                fn ($q) => $q->where('user_id', $user->id)->where('status', 'active')
            )->sum('balance');
        }

        // Pending charges = unpaid payment terms.
        $pendingChargesCount = $latestAssessment
            ? $latestAssessment->paymentTerms->filter(
                fn ($t) => in_array($t->status, \App\Enums\PaymentStatus::unpaidValues())
              )->count()
            : 0;

        // ── Notifications ─────────────────────────────────────────────────────
        $notifications = Notification::active()
            ->forUser($user->id)
            ->withinDateRange()
            ->forDueDateTrigger($user)
            ->distinct()
            ->orderByDesc('created_at')
            ->take(10)
            ->get()
            ->map(fn ($n) => [
                'id'               => $n->id,
                'title'            => $n->title,
                'message'          => $n->message,
                'type'             => $n->type,
                'start_date'       => $n->start_date,
                'end_date'         => $n->end_date,
                'due_date'         => $n->due_date,
                'payment_term_id'  => $n->payment_term_id,
                // ↓ Exposes the canonical term name set by Accounting
                'target_term_name' => $n->target_term_name,
                'target_role'      => $n->target_role,
                'is_active'        => $n->is_active,
                'is_complete'      => $n->is_complete,
                'dismissed_at'     => $n->dismissed_at,
                'created_at'       => $n->created_at,
            ]);

        // ── Recent transactions ───────────────────────────────────────────────
        $recentTransactions = $user->transactions()
            ->where('kind', 'payment')
            ->orderByDesc('created_at')
            ->take(5)
            ->get()
            ->map(fn ($txn) => [
                'id'              => $txn->id,
                'reference'       => $txn->reference,
                'or_number'       => $txn->or_number ?? null,
                'payment_channel' => $txn->payment_channel ?? ($txn->meta['payment_method'] ?? null),
                'type'            => $txn->type ?: 'General',
                'amount'          => $txn->amount,
                'status'          => $txn->status,
                'created_at'      => $txn->created_at,
            ]);

        // ── Payment reminders ─────────────────────────────────────────────────
        // Kept for data availability (AccountOverview, etc.) but no longer
        // rendered on the student dashboard.
        $paymentReminders = PaymentReminder::where('user_id', $user->id)
            ->where('status', '!=', PaymentReminder::STATUS_DISMISSED)
            ->orderByDesc('created_at')
            ->limit(10)
            ->get()
            ->unique(fn ($r) => $r->metadata['transaction_id'] ?? $r->id)
            ->values()
            ->map(fn ($r) => [
                'id'                  => $r->id,
                'type'                => $r->type,
                'message'             => $r->message,
                'outstanding_balance' => (float) $r->outstanding_balance,
                'status'              => $r->status,
                'read_at'             => $r->read_at,
                'sent_at'             => $r->sent_at,
                'trigger_reason'      => $r->trigger_reason,
            ]);

        $unreadReminderCount = PaymentReminder::where('user_id', $user->id)
            ->where('status', PaymentReminder::STATUS_SENT)
            ->count();

        $totalFees = $latestAssessment
            ? (float) $latestAssessment->total_assessment
            : 0;

        return Inertia::render('Student/Dashboard', [
            'account' => [
                'balance' => (float) $account->balance,
            ],

            'notifications'      => $notifications,
            'recentTransactions' => $recentTransactions,

            'latestAssessment' => $latestAssessment ? [
                'id'                => $latestAssessment->id,
                'assessment_number' => $latestAssessment->assessment_number,
                'total_assessment'  => (float) $latestAssessment->total_assessment,
                'status'            => $latestAssessment->status,
                'created_at'        => $latestAssessment->created_at,
            ] : null,

            'paymentTerms' => $paymentTerms->map(fn ($t) => [
                'id'         => $t->id,
                'term_name'  => $t->term_name,
                'term_order' => $t->term_order,
                'percentage' => $t->percentage,
                'amount'     => (float) $t->amount,
                'balance'    => (float) $t->balance,
                'due_date'   => $t->due_date,
                'status'     => $t->status,
                'remarks'    => $t->remarks,
                'paid_date'  => $t->paid_date,
            ])->values()->toArray(),

            'stats' => [
                'total_fees'            => $totalFees,
                'total_paid'            => (float) $totalPayments,
                'remaining_balance'     => (float) $remainingBalance,
                'pending_charges_count' => $pendingChargesCount,
            ],

            'paymentReminders'    => $paymentReminders,
            'unreadReminderCount' => $unreadReminderCount,
        ]);
    }
}