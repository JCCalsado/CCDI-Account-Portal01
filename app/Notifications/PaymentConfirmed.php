<?php

namespace App\Notifications;

use App\Models\Transaction;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\DatabaseMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PaymentConfirmed extends Notification
{
    use Queueable;

    public function __construct(
        private int    $transactionId,
        private float  $amount,
        private string $reference,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $transaction   = Transaction::with(['user', 'account', 'fee'])->find($this->transactionId);
        $studentName   = $notifiable->name ?? 'Student';
        $paymentMethod = $transaction ? ucwords(str_replace('_', ' ', $transaction->payment_channel ?? '')) : 'N/A';
        $datePaid      = $transaction ? $transaction->created_at->format('F d, Y') : now()->format('F d, Y');

        $mail = (new MailMessage)
            ->subject('Payment Receipt - CCDI Portal')
            ->greeting('Good day,')
            ->line('Thank you for your payment. Here are your transaction details:')
            ->line('**Student Name:** ' . $studentName)
            ->line('**Amount Paid:** ₱' . number_format($this->amount, 2))
            ->line('**Payment Method:** ' . $paymentMethod)
            ->line('**Date:** ' . $datePaid)
            ->line('**Reference No:** ' . $this->reference)
            ->line('**Status: PAID** ✓')
            ->line('Thank you for using our payment system!')
            ->salutation('- CCDI Payment Portal')
            ->action('View Account', route('student.account', ['tab' => 'history']));

        if ($transaction) {
            $pdf = Pdf::loadView('pdf.receipt', [
                'transaction'      => $transaction,
                'student'          => $notifiable,
                'balanceBefore'    => (float) ($notifiable->account->total_balance ?? 0) + (float) $this->amount,
                'currentBalance'   => (float) ($notifiable->account->total_balance ?? 0),
                'remainingBalance' => (float) ($notifiable->account->total_balance ?? 0),
            ])->setPaper('A4', 'portrait');

            $mail->attachData($pdf->output(), 'receipt-' . $this->reference . '.pdf', [
                'mime' => 'application/pdf',
            ]);
        }

        return $mail;
    }

    public function toDatabase(object $notifiable): DatabaseMessage
    {
        return new DatabaseMessage([
            'type'           => 'payment_confirmed',
            'title'          => 'Payment Recorded',
            'message'        => 'Payment of ₱' . number_format($this->amount, 2) . ' has been recorded.',
            'reference'      => $this->reference,
            'transaction_id' => $this->transactionId,
            'amount'         => $this->amount,
            'icon'           => 'check-circle',
            'color'          => 'green',
        ]);
    }
}