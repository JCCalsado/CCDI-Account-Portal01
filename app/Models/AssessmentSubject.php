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
        'lec_units'             => 'integer',
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

    public function getTotalUnitsAttribute(): int
    {
        return $this->lec_units + $this->lab_units;
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