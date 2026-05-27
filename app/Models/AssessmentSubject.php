<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssessmentSubject extends Model
{
    protected $table = 'assessment_subjects';

    protected $fillable = [
        'student_assessment_id',
        'subject_id',
        'code',
        'name',
        'lec_units',
        'lab_units',
        'is_nstp',
        'is_pathfit',
        'is_billable',
        'tuition_fee',
        'lab_fee',
        'total_fee',
        'nstp_billing_units',
        'sort_order',
    ];

    protected $casts = [
        'student_assessment_id' => 'integer',
        'subject_id'            => 'integer',
        // ✅ FIX #2: cast changed from 'integer' to 'decimal:1'.
        //    The DB column is being changed from unsignedTinyInteger to
        //    decimal(4,1) by migration fix_assessment_subjects_lec_units_column.
        //    Casting as 'integer' was silently truncating 1.5 → 1 on every
        //    read, so the NSTP billing snapshot in Show pages and PDF exports
        //    was always wrong. 'decimal:1' preserves the actual stored value.
        'lec_units'             => 'decimal:1',
        'lab_units'             => 'integer',
        'is_nstp'               => 'boolean',
        'is_pathfit'            => 'boolean',
        'is_billable'           => 'boolean',
        'tuition_fee'           => 'decimal:2',
        'lab_fee'               => 'decimal:2',
        'total_fee'             => 'decimal:2',
        'nstp_billing_units'    => 'decimal:1',
        'sort_order'            => 'integer',
    ];

    // ─── Relationships ────────────────────────────────────────────────────────

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(StudentAssessment::class, 'student_assessment_id');
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class, 'subject_id');
    }

    // ─── Computed Attributes ──────────────────────────────────────────────────

    /**
     * ✅ FIX #2: Return type changed from int to float.
     * lec_units can be 1.5 (NSTP) — returning int would truncate it.
     */
    public function getTotalUnitsAttribute(): float
    {
        return (float) $this->lec_units + (float) $this->lab_units;
    }

    // ─── Scopes ───────────────────────────────────────────────────────────────

    public function scopeBillable($query)
    {
        return $query->where('is_billable', true);
    }

    public function scopeNstp($query)
    {
        return $query->where('is_nstp', true);
    }
}