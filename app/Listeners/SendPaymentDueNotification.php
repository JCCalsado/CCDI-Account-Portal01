<?php

namespace App\Listeners;

use App\Events\DueAssigned;
use App\Notifications\PaymentDueNotification;
use App\Services\PhilSmsService;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class SendPaymentDueNotification implements ShouldQueue
{
    use InteractsWithQueue;

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

        // Email + database notification
        $user->notify(new PaymentDueNotification(
            $term->term_name,
            (float) $term->balance,
            $term->due_date,
        ));

        // SMS via PhilSMS
        $phone = $user->phone ?? null;
        if (! $phone) {
            return;
        }

        $amount  = number_format((float) $term->balance, 2);
        $dueDate = Carbon::parse($term->due_date)->format('M j, Y');
        $name    = $user->first_name ?? 'Student';

        $message = "Hi {$name}! Your CCDI {$term->term_name} payment of P{$amount} is due on {$dueDate}. "
                 . "Login to your portal to pay. -CCDI";

        $this->sms->send($phone, $message);
    }
}