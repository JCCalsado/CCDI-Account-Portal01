<?php

namespace App\Listeners;

use App\Events\PaymentRecorded;
use App\Notifications\PaymentConfirmed;
use App\Services\PhilSmsService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class SendPaymentConfirmationNotification implements ShouldQueue
{
    use InteractsWithQueue;

    public function __construct(private readonly PhilSmsService $sms) {}

    public function handle(PaymentRecorded $event): void
    {
        $user = $event->user;

        // ── Email + database notification ─────────────────────────────────
        $user->notify(new PaymentConfirmed(
            $event->transactionId,
            $event->amount,
            $event->reference,
        ));

        // ── SMS via PhilSMS ───────────────────────────────────────────────
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