<?php

namespace App\Notifications;

use App\Models\StudentPaymentTerm;
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
        $paymentMethod = $transaction
            ? ucwords(str_replace('_', ' ', $transaction->payment_channel ?? ''))
            : 'N/A';
        $datePaid = $transaction
            ? $transaction->created_at->format('F d, Y')
            : now()->format('F d, Y');

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
            // ── Resolve the assessment for the receipt PDF ────────────────────
            // The receipt.blade.php requires $assessment. Resolve it from the
            // transaction meta (assessment_id written by StudentPaymentService),
            // or fall back to the student's most recent assessment.
            //
            // The notification runs after finalization, so the transaction meta
            // should always contain assessment_id at this point.
            $assessment = null;

            $assessmentId = $transaction->meta['assessment_id'] ?? null;

            if ($assessmentId) {
                $assessment = \App\Models\StudentAssessment::find($assessmentId);
            }

            // Fallback: find via the starting term stored in meta.
            if (! $assessment) {
                $termId = $transaction->meta['selected_term_id'] ?? null;
                if ($termId) {
                    $term = StudentPaymentTerm::with('assessment')->find($termId);
                    $assessment = $term?->assessment;
                }
            }

            // Last resort: use the student's most recent assessment.
            if (! $assessment) {
                $assessment = \App\Models\StudentAssessment::where('user_id', $notifiable->id)
                    ->orderByDesc('created_at')
                    ->first();
            }

            if ($assessment) {
                // Build academic term label for the receipt header.
                $semLabels = [
                    '1st'     => '1st Sem',
                    '2nd'     => '2nd Sem',
                    'Summer'  => 'Summer',
                    '1st Sem' => '1st Sem',
                    '2nd Sem' => '2nd Sem',
                ];
                $semesterLabel = $semLabels[$assessment->semester] ?? $assessment->semester;
                $academicTerm  = trim(($assessment->school_year ?? '') . ', ' . $semesterLabel);

                $totalAssessment  = (float) $assessment->total_assessment;
                $remainingBalance = round((float) $assessment->outstanding_balance, 2);
                $totalPaid        = round($totalAssessment - $remainingBalance, 2);

                // Collect all paid transactions for this assessment for the receipt.
                $allTransactions = \App\Models\Transaction::where('user_id', $notifiable->id)
                    ->where('kind', 'payment')
                    ->where('status', 'paid')
                    ->whereJsonContains('meta->assessment_id', $assessment->id)
                    ->orderBy('paid_at', 'asc')
                    ->get();

                try {
                    $pdf = Pdf::loadView('pdf.receipt', [
                        'transactions'     => $allTransactions->isNotEmpty() ? $allTransactions : collect([$transaction]),
                        'assessment'       => $assessment,
                        'student'          => $notifiable,
                        'academicTerm'     => $academicTerm,
                        'totalAssessment'  => $totalAssessment,
                        'totalPaid'        => $totalPaid,
                        'remainingBalance' => $remainingBalance,
                    ])->setPaper('A4', 'portrait');

                    $mail->attachData($pdf->output(), 'receipt-' . $this->reference . '.pdf', [
                        'mime' => 'application/pdf',
                    ]);
                } catch (\Throwable $e) {
                    // PDF generation failure must NOT break the email delivery.
                    // Log the error and send the email without attachment.
                    \Illuminate\Support\Facades\Log::error('PaymentConfirmed: PDF attachment failed', [
                        'transaction_id' => $this->transactionId,
                        'reference'      => $this->reference,
                        'error'          => $e->getMessage(),
                    ]);
                }
            }
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