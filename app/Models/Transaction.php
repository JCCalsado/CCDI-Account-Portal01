<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use App\Services\AccountService;

class Transaction extends Model
{
    protected $fillable = [
        'user_id', 'account_id', 'fee_id', 'reference',
        'payment_channel', 'kind', 'type', 'amount', 'status',
        'paid_at', 'meta', 'year', 'semester', 'or_number',
        // ✅ FIX #12: 'category' added — column added by migration
        //    2025_09_23_130918_add_category_to_transactions_table but was
        //    missing from $fillable. Any write to transaction.category was
        //    silently dropped by mass-assignment protection.
        'category',
    ];

    protected $casts = [
        'meta'    => 'array',
        'paid_at' => 'datetime',
        'amount'  => 'decimal:2',
    ];

    // ─── Relationships ────────────────────────────────────────────────────────

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function fee(): BelongsTo
    {
        return $this->belongsTo(Fee::class);
    }

    public function workflowInstances(): MorphMany
    {
        return $this->morphMany(WorkflowInstance::class, 'workflowable');
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Returns the pending WorkflowInstance for this transaction, if any.
     */
    public function pendingApproval(): ?WorkflowInstance
    {
        return $this->workflowInstances()
            ->where('status', 'in_progress')
            ->latest()
            ->first();
    }

    /**
     * Returns a human-readable description for this transaction.
     */
    public function getDescriptionAttribute(): string
    {
        return $this->meta['description']
            ?? $this->meta['fee_name']
            ?? $this->type
            ?? ucfirst($this->kind ?? 'transaction');
    }

    /**
     * Returns the term label for this transaction.
     * Format: "2026 1st Sem" or empty string if not set.
     */
    public function getTermLabelAttribute(): string
    {
        $parts = array_filter([$this->year, $this->semester]);
        return implode(' ', $parts);
    }

    // ─── Model Events ─────────────────────────────────────────────────────────

    protected static function booted(): void
    {
        static::saved(function (Transaction $transaction) {
            AccountService::recalculate($transaction->user);
        });
    }
}