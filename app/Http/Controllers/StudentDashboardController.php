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
        // Registration creates the Account row. This guard exists only for
        // accounts created by admin outside of the registration flow.
        // We intentionally do NOT load the transactions relation here —
        // Vue only needs the balance scalar.
        $account = $user->account()->firstOrCreate(
            ['user_id' => $user->id],
            ['balance' => 0]
        );

        // ── Latest assessment + payment terms ─────────────────────────────────
        // Source of truth for balances. Eager-load paymentTerms in ONE query,
        // then sort the already-loaded collection in PHP — no second DB call.
        $latestAssessment = StudentAssessment::where('user_id', $user->id)
            ->with(['paymentTerms' => fn ($q) => $q->orderBy('term_order')])
            ->latest('created_at')
            ->first();

        $paymentTerms     = collect();
        $remainingBalance = 0;

        if ($latestAssessment) {
            $paymentTerms     = $latestAssessment->paymentTerms; // already loaded — no extra query
            $remainingBalance = $paymentTerms->sum('balance');
        }

        // ── Financial aggregates ─────────────────────────────────────────────
        // kind='charge' transactions are no longer created. Derive totals from
        // StudentAssessment and StudentPaymentTerm — the real source of truth.
        $totalPayments = $user->transactions()
            ->where('kind', 'payment')
            ->where('status', 'paid')
            ->sum('amount');

        // Fallback: if no payment terms loaded, sum all active term balances directly.
        if ($paymentTerms->isEmpty()) {
            $remainingBalance = (float) StudentPaymentTerm::whereHas(
                'assessment',
                fn ($q) => $q->where('user_id', $user->id)->where('status', 'active')
            )->sum('balance');
        }

        // Pending charges = unpaid payment terms (replaces pending charge transactions).
        $pendingChargesCount = $latestAssessment
            ? $latestAssessment->paymentTerms->filter(
                fn ($t) => in_array($t->status, \App\Enums\PaymentStatus::unpaidValues())
              )->count()
            : 0;

        // ── Notifications ─────────────────────────────────────────────────────
        // distinct() prevents duplicate rows that can appear when a student matches
        // multiple OR branches in scopeForUser (e.g. direct user_id + JSON user_ids,
        // or when term_ids contains multiple IDs that each satisfy the subquery).
        $notifications = Notification::active()
            ->forUser($user->id)
            ->withinDateRange()
            ->forDueDateTrigger($user)
            ->distinct()
            ->orderByDesc('created_at')
            ->take(10)
            ->get()
            ->map(fn ($n) => [
                'id'              => $n->id,
                'title'           => $n->title,
                'message'         => $n->message,
                'type'            => $n->type,
                'start_date'      => $n->start_date,
                'end_date'        => $n->end_date,
                'due_date'        => $n->due_date,
                'payment_term_id' => $n->payment_term_id,
                'target_role'     => $n->target_role,
                'is_active'       => $n->is_active,
                'is_complete'     => $n->is_complete,
                'dismissed_at'    => $n->dismissed_at,
                'created_at'      => $n->created_at,
            ]);

        // ── Recent transactions ───────────────────────────────────────────────
        // Only show payment transactions — kind='charge' (ASMT- assessment debit
        // entries) are internal ledger rows, not cashier receipts. They must not
        // appear in the student-facing "Recent Transactions" widget.
        //
        // or_number  — cashier-assigned OR number for cash/manual payments.
        // payment_channel — how the payment was made (cash, gcash, bank_transfer, etc.)
        // Both are used by Vue to decide what label and value to show as the reference.
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
        $paymentReminders = PaymentReminder::where('user_id', $user->id)
            ->where('status', '!=', PaymentReminder::STATUS_DISMISSED)
            ->orderByDesc('created_at')
            ->limit(10)
            ->get()
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
            // Only scalar — no transaction relation serialised over the wire
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