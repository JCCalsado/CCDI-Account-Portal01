<?php

namespace App\Notifications;

use App\Models\StudentRegistration;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class NewRegistrationSubmitted extends Notification
{
    use Queueable;

    public function __construct(public StudentRegistration $registration) {}

    public function via($notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail($notifiable): MailMessage
    {
        $url = route('accounting.registrations.show', $this->registration);

        return (new MailMessage)
            ->subject('New Student Registration Pending Review — CCDI Portal')
            ->greeting('Hello, ' . $notifiable->first_name . '.')
            ->line('A new student registration has been submitted and requires your review.')
            ->line('**Applicant:** ' . $this->registration->full_name)
            ->line('**Course:** ' . $this->registration->course . ' — ' . $this->registration->year_level)
            ->line('**Student Type:** ' . ucfirst($this->registration->student_type))
            ->line('**Contact:** ' . $this->registration->contact_number)
            ->line('**Email:** ' . $this->registration->email)
            ->action('Review Registration', $url)
            ->line('Please log in to the CCDI Accounting Portal to review, approve, reject, or request revision.')
            ->salutation('CCDI Account Portal');
    }

    public function toArray($notifiable): array
    {
        return [
            'registration_id' => $this->registration->id,
            'applicant_name'  => $this->registration->full_name,
            'course'          => $this->registration->course,
            'submitted_at'    => $this->registration->submitted_at?->toIso8601String(),
        ];
    }
}