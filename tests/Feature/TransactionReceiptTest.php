<?php
public function test_receipt_returns_pdf_for_paid_transaction(): void
{
    $user = User::factory()->create(['role' => 'student']);
    $transaction = Transaction::factory()->create([
        'user_id' => $user->id,
        'status'  => 'paid',
        'kind'    => 'payment',
    ]);

    $this->actingAs($user)
         ->get(route('transactions.receipt', $transaction))
         ->assertOk()
         ->assertHeader('content-type', 'application/pdf');
}