<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subject extends Model
{
    protected $fillable = [
        'code',
        'name',
        'units',
        'lec_units',
        'lab_units',
        'is_nstp',
        'price_per_unit',
        'year_level',
        'semester',
        'course',
        'description',
        'has_lab',
        'lab_fee',
        'is_active',
    ];

    protected $casts = [
        'units'          => 'integer',
        'lec_units'      => 'float',    // decimal(4,1) — supports fractional values (e.g. NSTP = 1.5)
        'lab_units'      => 'integer',
        'is_nstp'        => 'boolean',
        'price_per_unit' => 'decimal:2',
        'lab_fee'        => 'decimal:2',
        'has_lab'        => 'boolean',
        'is_active'      => 'boolean',
    ];

    // ─── Accessors ────────────────────────────────────────────────────────────

    /**
     * Total units (LEC + LAB combined).
     *
     * Returns float because lec_units is decimal(4,1) and may be fractional.
     * Callers that need an integer display value should cast at the call site.
     */
    public function getTotalUnitsAttribute(): float
    {
        return ($this->lec_units ?? 0.0) + ($this->lab_units ?? 0);
    }

    /**
     * Computed total cost for this subject at current config rates.
     *
     * Used for display and seeder preview only. Authoritative billing uses
     * AssessmentService::computeSubjectFees(), which reads from fee_settings.
     *
     * NSTP subjects: lec_units is read directly from the DB — no hardcoded
     * override. The DB value is the source of truth per the agreed architecture.
     */
    public function getTotalCostAttribute(): float
    {
        $rate   = (float) config('fees.tuition_per_unit',    364.00);
        $labFee = (float) config('fees.lab_fee_per_subject', 1656.00);

        $tuition = ($this->lec_units ?? 0.0) * $rate;
        $lab     = ($this->lab_units ?? 0) > 0 ? $labFee : 0.0;

        return round($tuition + $lab, 2);
    }

    // ─── Relationships ────────────────────────────────────────────────────────

    public function enrollments(): HasMany
    {
        return $this->hasMany(StudentEnrollment::class);
    }

    // ─── Scopes ───────────────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForTerm($query, string $yearLevel, string $semester, string $course)
    {
        return $query->where('year_level', $yearLevel)
                     ->where('semester', $semester)
                     ->where('course', $course);
    }
}