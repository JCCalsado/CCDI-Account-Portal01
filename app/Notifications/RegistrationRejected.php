<?php

namespace App\Notifications;

use App\Models\StudentRegistration;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class RegistrationRejected extends Notification
{
    use Queueable;

    public function __construct(public StudentRegistration $registration) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $registerUrl = route('register');

        return (new MailMessage)
            ->subject('CCDI Registration Status Update')
            ->greeting('Hello, ' . $this->registration->first_name . '.')
            ->line('We regret to inform you that your registration submission could not be approved at this time.')
            ->line('**Reason:** ' . $this->registration->rejection_reason)
            ->line('---')
            ->line('If you believe this is an error, or if you have corrected the issue, you may submit a new registration.')
            ->action('Submit New Registration', $registerUrl)
            ->line('For questions, please contact the CCDI Accounting Department directly.')
            ->salutation('CCDI Accounting Department');
    }
}