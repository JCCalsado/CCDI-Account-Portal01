<?php

namespace App\Listeners;

use App\Events\PaymentRecorded;
use App\Models\Notification;
use App\Models\StudentAssessment;
use Illuminate\Contracts\Queue\ShouldQueue;

class MarkNotificationCompleteOnPayment implements ShouldQueue
{
    /**
     * When a payment is recorded, mark all payment_due notification banners
     * as complete once the student's full assessment balance is cleared.
     */
    public function handle(PaymentRecorded $event): void
    {
        $user = $event->user;

        $studentAssessment = $this->resolveAssessment($user, $event->transactionId);

        if (! $studentAssessment) {
            return;
        }

        $totalBalance = $studentAssessment->paymentTerms()
            ->where('balance', '>', 0)
            ->sum('balance');

        if ($totalBalance <= 0) {
            Notification::where('user_id', $user->id)
                ->where('type', 'payment_due')
                ->where('is_complete', false)
                ->update(['is_complete' => true]);
        }
    }

    /**
     * Resolve which StudentAssessment this payment belongs to.
     *
     * Priority:
     *   1. Transaction meta['assessment_id']  (explicit — most accurate)
     *   2. Latest assessment by created_at    (fallback for older records)
     *
     * Uses a direct Eloquent query in the fallback instead of
     * $user->assessments() to stay independent of relationship definitions.
     */
    private function resolveAssessment(\App\Models\User $user, int $transactionId): ?StudentAssessment
    {
        $transaction = $user->transactions()->find($transactionId);

        if ($transaction && ! empty($transaction->meta['assessment_id'])) {
            $assessment = StudentAssessment::find($transaction->meta['assessment_id']);
            if ($assessment && $assessment->user_id === $user->id) {
                return $assessment;
            }
        }

        // Fallback: query directly — avoids any dependency on User model relationships.
        return StudentAssessment::where('user_id', $user->id)
            ->latest('created_at')
            ->first();
    }
}