<?php

namespace App\Listeners;

use App\Events\DueAssigned;
use App\Notifications\PaymentDueNotification;
use App\Services\PhilSmsService;
use Carbon\Carbon;

/**
 * INTENTIONALLY NOT implementing ShouldQueue.
 *
 * Reason: CCDI Portal is deployed on shared Hostinger hosting which cannot
 * run a persistent queue worker (no supervisor / Horizon support).
 * Making this listener queued means the SMS job sits in the `jobs` table
 * indefinitely and is never processed — which is exactly why "system says
 * success, phone got nothing."
 *
 * SMS is sent synchronously here. The HTTP call to PhilSMS takes ~1-2 seconds
 * and is wrapped in a try/catch inside PhilSmsService::send(), so it will
 * never throw or block the request fatally.
 *
 * If the hosting is ever migrated to a VPS with supervisor/Horizon,
 * re-add `implements ShouldQueue` and `use InteractsWithQueue`.
 */
class SendPaymentDueNotification
{
    public function __construct(private readonly PhilSmsService $sms) {}

    public function handle(DueAssigned $event): void
    {
        $term = $event->term;
        $user = $event->user;

        if (! $term->due_date) {
            return;
        }

        $daysUntilDue = now()->diffInDays($term->due_date, false);
        if ($daysUntilDue < 0) {
            return;
        }

        // Email + database notification (PaymentDueNotification uses its own
        // mail channel — queued or sync depending on MAIL_MAILER config)
        $user->notify(new PaymentDueNotification(
            $term->term_name,
            (float) $term->balance,
            $term->due_date,
        ));

        // SMS via PhilSMS — synchronous, best-effort
        $phone = $user->phone ?? null;
        if (! $phone) {
            return;
        }

        $amount  = number_format((float) $term->balance, 2);
        $dueDate = Carbon::parse($term->due_date)->format('M j, Y');
        $name    = $user->first_name ?? 'Student';

        $message = "Hi {$name}! Your CCDI {$term->term_name} payment of P{$amount} "
                 . "is due on {$dueDate}. Login to your portal to pay. -CCDI";

        $this->sms->send($phone, $message);
    }
}