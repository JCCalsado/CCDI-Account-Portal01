<?php

namespace App\Notifications;

use App\Models\StudentRegistration;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to the student immediately after they submit their registration form.
 *
 * Purpose: acknowledge receipt, give them their tracking token, and set the
 * expectation that accounting will review before their account becomes active.
 *
 * Fired in: RegisteredUserController::store() — after DB::commit(), before
 * notifyAccountingStaff().
 */
class RegistrationReceived extends Notification
{
    use Queueable;

    public function __construct(public StudentRegistration $registration) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $statusUrl = route('registration.status', [
            'token' => $this->registration->tracking_token,
        ]);

        return (new MailMessage)
            ->subject('We Received Your CCDI Registration — ' . $this->registration->tracking_token)
            ->greeting('Hello, ' . $this->registration->first_name . '!')
            ->line('Your registration has been successfully submitted to the CCDI Accounting Department.')
            ->line('**Your Tracking Token:** `' . $this->registration->tracking_token . '`')
            ->line('Keep this token — you can use it to check your registration status at any time.')
            ->line('---')
            ->line('**What happens next?**')
            ->line('The Accounting Department will review your submission. You will receive an email notification once a decision has been made.')
            ->line('If any corrections are needed, you will receive a revision request with specific instructions.')
            ->action('Check My Registration Status', $statusUrl)
            ->line('If you did not submit this registration, please disregard this email.')
            ->salutation('CCDI Accounting Department');
    }
}