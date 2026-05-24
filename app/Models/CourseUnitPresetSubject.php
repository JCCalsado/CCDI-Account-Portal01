<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CourseUnitPresetSubject extends Model
{
    protected $table = 'course_unit_preset_subjects';

    protected $fillable = [
        'course_unit_preset_id',
        'subject_id',
        'lec_units',
        'lab_units',
        'tuition_fee',
        'lab_fee',
        'total_fee',
        'is_nstp',
        'is_pathfit',
        'sort_order',
    ];

    protected $casts = [
        'course_unit_preset_id' => 'integer',
        'subject_id'            => 'integer',
        'lec_units'             => 'integer',
        'lab_units'             => 'integer',
        'tuition_fee'           => 'decimal:2',
        'lab_fee'               => 'decimal:2',
        'total_fee'             => 'decimal:2',
        'is_nstp'               => 'boolean',
        'is_pathfit'            => 'boolean',
        'sort_order'            => 'integer',
    ];

    // ─── Relationships ────────────────────────────────────────────────────────

    public function preset(): BelongsTo
    {
        return $this->belongsTo(CourseUnitPreset::class, 'course_unit_preset_id');
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class, 'subject_id');
    }
}