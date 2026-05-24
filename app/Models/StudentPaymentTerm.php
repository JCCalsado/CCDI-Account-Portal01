<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentPaymentTerm extends Model
{
    protected $fillable = [
        'student_assessment_id',
        'term_name',
        'term_order',
        'percentage',
        'amount',
        'balance',
        'payment_intent_id',
        'due_date',
        'status',
        'remarks',
        'paid_date',
        'carryover_from_term_id',
        'carryover_amount',
    ];

    protected $casts = [
        'amount'           => 'decimal:2',
        'balance'          => 'decimal:2',
        'carryover_amount' => 'decimal:2',
        'due_date'         => 'date',
        'paid_date'        => 'datetime',
    ];

    // ── Status constants — string aliases for PaymentStatus enum values ────────
    // These constants exist for backward compatibility with any code that uses
    // StudentPaymentTerm::STATUS_*. New code should use PaymentStatus::* directly.

    const STATUS_PENDING   = PaymentStatus::PENDING->value;    // 'pending'
    const STATUS_PARTIAL   = PaymentStatus::PARTIAL->value;    // 'partial'
    const STATUS_PAID      = PaymentStatus::PAID->value;       // 'paid'
    const STATUS_PROCESSED = PaymentStatus::PROCESSED->value;  // 'processed'
    const STATUS_OVERDUE   = 'overdue';                        // display-only flag

    /**
     * ⚠️ DEPRECATED: StudentPaymentTerm::TERMS is OUT OF SYNC with config/fees.php
     *
     * The percentages below are stale and are NOT used by the application.
     * Use config('fees.terms') instead, which is the authoritative source.
     *
     * This constant exists only for backward compatibility and should not
     * be referenced in new code. It will be removed in a future migration.
     *
     * @deprecated Use config('fees.terms') instead
     */
    const TERMS = [
        1 => ['name' => 'Upon Registration', 'percentage' => 42.15],
        2 => ['name' => 'Prelim',            'percentage' => 17.86],
        3 => ['name' => 'Midterm',           'percentage' => 17.86],
        4 => ['name' => 'Semi-Final',        'percentage' => 14.88],
        5 => ['name' => 'Final',             'percentage' =>  7.25],
    ];

    // ─────────────────────────────────────────────────────────────────────────
    // RELATIONS
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * The assessment this term belongs to.
     * Use term → assessment → user to reach the owning student.
     */
    public function assessment(): BelongsTo
    {
        return $this->belongsTo(StudentAssessment::class, 'student_assessment_id');
    }

    /**
     * Source term that carried balance into this term, if any.
     */
    public function carryoverFromTerm(): BelongsTo
    {
        return $this->belongsTo(self::class, 'carryover_from_term_id');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // HELPERS
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Check if this term is overdue.
     * A PROCESSED term is never overdue — it is closed.
     */
    public function isOverdue(): bool
    {
        if ($this->status === self::STATUS_PROCESSED || $this->status === self::STATUS_PAID) {
            return false;
        }
        return $this->status === self::STATUS_PENDING
            && $this->due_date !== null
            && now()->isAfter($this->due_date);
    }

    /**
     * Get total accumulated balance (including carryover).
     * Always reads the live DB value — never cached.
     */
    public function getAccumulatedBalanceAttribute(): float
    {
        return (float) $this->balance;
    }

    /**
     * Check if this term received a carry-over from a previous term.
     *
     * FIX: the previous implementation used str_contains($remarks, 'carries')
     * which never matched because remarks use 'Carry-over' or 'carried to'.
     * This version checks both patterns correctly.
     */
    public function hasCarryover(): bool
    {
        if (empty($this->remarks)) {
            return false;
        }

        return str_contains($this->remarks, 'Carry-over')
            || str_contains($this->remarks, 'carried to')
            || $this->carryover_from_term_id !== null;
    }

    /**
     * Whether this term is closed — either fully paid or processed (carried forward).
     */
    public function isClosed(): bool
    {
        return $this->status === self::STATUS_PAID
            || $this->status === self::STATUS_PROCESSED;
    }

    /**
     * Human-readable display status.
     * PROCESSED shows as "Processed" not "Partial" — they are semantically different:
     *   PARTIAL  → balance remains on THIS term; it is still payable
     *   PROCESSED → balance was moved to the NEXT term; this term is closed
     */
    public function getDisplayStatusAttribute(): string
    {
        return match ($this->status) {
            self::STATUS_PAID      => 'Paid',
            self::STATUS_PROCESSED => 'Processed',
            self::STATUS_PARTIAL   => 'Partial',
            self::STATUS_PENDING   => $this->isOverdue() ? 'Overdue' : 'Pending',
            'unpaid'               => 'Pending',
            default                => ucfirst($this->status),
        };
    }
}