<?php

namespace App\Listeners;

use App\Events\PaymentRecorded;
use App\Notifications\PaymentConfirmed;
use App\Services\PhilSmsService;

/**
 * INTENTIONALLY NOT implementing ShouldQueue.
 *
 * Same reason as SendPaymentDueNotification — shared Hostinger hosting
 * cannot run a persistent queue worker. ShouldQueue causes SMS jobs to
 * pile up in the `jobs` table and never execute.
 *
 * SMS is sent synchronously. PhilSmsService::send() handles all exceptions
 * internally and never throws, so this is safe to call in the request cycle.
 */
class SendPaymentConfirmationNotification
{
    public function __construct(private readonly PhilSmsService $sms) {}

    public function handle(PaymentRecorded $event): void
    {
        $user = $event->user;

        // Email + database notification
        $user->notify(new PaymentConfirmed(
            $event->transactionId,
            $event->amount,
            $event->reference,
        ));

        // SMS via PhilSMS — synchronous, best-effort
        $phone = $user->phone ?? null;
        if (! $phone) {
            return;
        }

        $name    = $user->first_name ?? 'Student';
        $amount  = number_format($event->amount, 2);

        $message = "Hi {$name}! Your CCDI payment of P{$amount} has been confirmed. "
                 . "Ref: {$event->reference}. Login to the portal to view your receipt. -CCDI";

        $this->sms->send($phone, $message);
    }
}