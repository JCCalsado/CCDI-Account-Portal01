<?php

namespace App\Notifications;

use App\Models\StudentRegistration;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

class RegistrationNeedsRevision extends Notification
{
    use Queueable;

    public function __construct(public StudentRegistration $registration) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        // Signed URL expires in 72 hours — student must use this to edit
        $revisionUrl = URL::temporarySignedRoute(
            'registration.edit',
            now()->addHours(72),
            ['token' => $this->registration->tracking_token]
        );

        return (new MailMessage)
            ->subject('Action Required: Your CCDI Registration Needs Revision')
            ->greeting('Hello, ' . $this->registration->first_name . '.')
            ->line('The Accounting Department has reviewed your registration and has requested some corrections.')
            ->line('**What to revise:**')
            ->line($this->registration->revision_notes)
            ->line('---')
            ->line('Please click the button below to update your registration. The link is valid for **72 hours**.')
            ->action('Update My Registration', $revisionUrl)
            ->line('Once you resubmit, your registration will be reviewed again by the Accounting Department.')
            ->salutation('CCDI Accounting Department');
    }
}