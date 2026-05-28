<?php

namespace App\Notifications;

use App\Models\StudentRegistration;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class RegistrationApproved extends Notification
{
    use Queueable;

    public function __construct(
        public StudentRegistration $registration,
        public User $user
    ) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $loginUrl = route('login');

        return (new MailMessage)
            ->subject('Your CCDI Registration Has Been Approved!')
            ->greeting('Hello, ' . $this->registration->first_name . '!')
            ->line('Great news — your registration has been reviewed and **approved** by the Accounting Department.')
            ->line('Your CCDI Account Portal account is now active.')
            ->line('---')
            ->line('**Account ID:** ' . $this->user->account_id)
            ->line('**Email:** ' . $this->user->email)
            ->line('**Course:** ' . $this->user->course . ' — ' . $this->user->year_level)
            ->line('---')
            ->line('You can now log in using the email and password you provided during registration.')
            ->action('Log In to the Portal', $loginUrl)
            ->line('If you have trouble logging in, use the "Forgot Password" link on the login page.')
            ->salutation('CCDI Accounting Department');
    }
}