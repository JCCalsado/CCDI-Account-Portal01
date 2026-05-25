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
        'lec_units'      => 'float',    // decimal(4,1) — supports NSTP at 1.5
        'lab_units'      => 'integer',
        'price_per_unit' => 'decimal:2',
        'lab_fee'        => 'decimal:2',
        'has_lab'        => 'boolean',
        'is_active'      => 'boolean',
    ];

    /**
     * Get total units (LEC + LAB combined).
     *
     * Returns float because lec_units may be fractional (e.g. NSTP = 1.5).
     * Callers that need an integer display value should cast at the call site.
     */
    public function getTotalUnitsAttribute(): float
    {
        return ($this->lec_units ?? 0.0) + ($this->lab_units ?? 0);
    }

    /**
     * Get the computed total cost for this subject.
     *
     * Tuition  = lec_units × config('fees.tuition_per_unit')
     * Lab      = lab_units > 0 ? config('fees.lab_fee_per_subject') : 0  (flat per subject)
     * Total    = Tuition + Lab
     *
     * NOTE: For NSTP subjects, AssessmentService overrides lec_units with
     * NSTP_MINIMUM_UNITS (1.5) at compute time. This accessor uses the stored
     * DB value directly and is used for display/seeder purposes only.
     */
    public function getTotalCostAttribute(): float
    {
        $rate   = (float) config('fees.tuition_per_unit',    364.00);
        $labFee = (float) config('fees.lab_fee_per_subject', 1656.00);

        $tuition = ($this->lec_units ?? 0.0) * $rate;
        $lab     = ($this->lab_units ?? 0) > 0 ? $labFee : 0.0;

        return round($tuition + $lab, 2);
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(StudentEnrollment::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForTerm($query, $yearLevel, $semester, $course)
    {
        return $query->where('year_level', $yearLevel)
                     ->where('semester', $semester)
                     ->where('course', $course);
    }
}