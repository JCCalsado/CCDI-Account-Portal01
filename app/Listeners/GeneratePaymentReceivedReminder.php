<?php

namespace App\Listeners;

use App\Events\PaymentRecorded;
use App\Models\PaymentReminder;
use App\Models\StudentAssessment;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class GeneratePaymentReceivedReminder
{
    public function handle(PaymentRecorded $event): void
    {
        $user = $event->user;

        // ── IDEMPOTENCY GUARD ──────────────────────────────────────────────────
        // If a PaymentReminder already exists for this exact transaction_id
        // (stored in metadata), do NOT create a duplicate row.
        // This guards against PaymentRecorded firing twice for the same
        // transaction (e.g. staff-direct pay + approval path overlap, or
        // retry-on-failure from a queued listener).
        if ($event->transactionId !== null) {
            $alreadyExists = PaymentReminder::where('user_id', $user->id)
                ->whereJsonContains('metadata->transaction_id', $event->transactionId)
                ->exists();

            if ($alreadyExists) {
                Log::info('GeneratePaymentReceivedReminder: skipped duplicate — reminder already exists for transaction', [
                    'user_id'        => $user->id,
                    'transaction_id' => $event->transactionId,
                ]);
                return;
            }
        }

        $assessment = $this->resolveAssessment($user, $event->transactionId);

        if (! $assessment) {
            return;
        }

        $paymentTerms = $assessment->paymentTerms()
            ->where('balance', '>', 0)
            ->orderBy('term_order')
            ->get();

        $remainingBalance = $paymentTerms->sum('balance');

        if ($remainingBalance > 0) {
            $message = 'Payment of ₱' . number_format($event->amount, 2)
                     . ' received. Outstanding balance: ₱' . number_format($remainingBalance, 2);
            $type = PaymentReminder::TYPE_PARTIAL_PAYMENT;
        } else {
            $message = 'Payment of ₱' . number_format($event->amount, 2)
                     . ' received. Account balance fully paid!';
            $type = PaymentReminder::TYPE_PAYMENT_RECEIVED;
        }

        PaymentReminder::create([
            'user_id'                 => $user->id,
            'student_assessment_id'   => $assessment->id,
            'student_payment_term_id' => $paymentTerms->first()?->id,
            'type'                    => $type,
            'message'                 => $message,
            'outstanding_balance'     => $remainingBalance,
            'status'                  => PaymentReminder::STATUS_SENT,
            'in_app_sent'             => true,
            'sent_at'                 => now(),
            'trigger_reason'          => PaymentReminder::TRIGGER_ADMIN_UPDATE,
            'triggered_by'            => $event->triggeredBy,
            'metadata'                => [
                'transaction_id' => $event->transactionId,
                'reference'      => $event->reference,
                'payment_amount' => $event->amount,
            ],
        ]);

        $nextUnpaidTerm = $paymentTerms->first();
        $dueDate        = $nextUnpaidTerm?->due_date;

        if ($dueDate !== null && ! $dueDate instanceof Carbon) {
            try {
                $dueDate = Carbon::parse($dueDate);
            } catch (\Throwable $e) {
                Log::warning('GeneratePaymentReceivedReminder: could not parse due_date, skipping notification', [
                    'user_id'       => $user->id,
                    'assessment_id' => $assessment->id,
                    'raw_due_date'  => $dueDate,
                    'error'         => $e->getMessage(),
                ]);
                $dueDate = null;
            }
        }

        if ($nextUnpaidTerm && $dueDate instanceof Carbon) {
            $user->notify(new \App\Notifications\PaymentDueNotification(
                $nextUnpaidTerm->term_name ?? 'Payment',
                (float) $remainingBalance,
                $dueDate,
            ));
        } else {
            Log::info('GeneratePaymentReceivedReminder: skipped PaymentDueNotification — no unpaid term or due_date is null', [
                'user_id'           => $user->id,
                'assessment_id'     => $assessment->id,
                'remaining_balance' => $remainingBalance,
                'next_term_id'      => $nextUnpaidTerm?->id,
                'due_date_raw'      => $nextUnpaidTerm?->due_date,
            ]);
        }
    }

    private function resolveAssessment(\App\Models\User $user, int $transactionId): ?StudentAssessment
    {
        $transaction = $user->transactions()->find($transactionId);

        if ($transaction && ! empty($transaction->meta['assessment_id'])) {
            $assessment = StudentAssessment::find($transaction->meta['assessment_id']);
            if ($assessment && $assessment->user_id === $user->id) {
                return $assessment;
            }
        }

        return StudentAssessment::where('user_id', $user->id)
            ->latest('created_at')
            ->first();
    }
}