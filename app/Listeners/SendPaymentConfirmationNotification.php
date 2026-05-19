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

        // Email + database notification
        $notification = new PaymentConfirmed(
            $event->transactionId,
            $event->amount,
            $event->reference,
        );

        $user->notify($notification);

        // SMS via PhilSMS — delegated to the notification itself
        // so the message template stays co-located with the notification class.
        $notification->sendSms($user);
    }
}