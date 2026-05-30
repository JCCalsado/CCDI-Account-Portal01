<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CourseUnitPreset extends Model
{
    protected $table = 'course_unit_presets';

    protected $fillable = [
        'course',
        'year_level',
        'semester',
        'lec_units',
        'lab_units',
        'lab_subject_count',
        'is_active',
    ];

    protected $casts = [
        'lec_units'         => 'integer',
        'lab_units'         => 'integer',
        'lab_subject_count' => 'integer',
        'is_active'         => 'boolean',
    ];

    // ─── Relationships ────────────────────────────────────────────────────────

    /**
     * The subjects that make up this preset's curriculum.
     *
     * One subject can only appear once per preset (unique constraint on the
     * course_unit_preset_subjects table). Ordered by sort_order then id so
     * the UI always displays subjects in a consistent sequence.
     */
    public function presetSubjects(): HasMany
    {
        return $this->hasMany(CourseUnitPresetSubject::class, 'course_unit_preset_id')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    // ─── Static Helpers ───────────────────────────────────────────────────────

    /**
     * All distinct course names that have at least one active preset.
     * Used to populate course dropdowns in the Curriculum Preset UI.
     */
    public static function supportedCourses(): array
    {
        return self::where('is_active', true)
            ->distinct()
            ->orderBy('course')
            ->pluck('course')
            ->toArray();
    }

    /**
     * Find the active preset for a given course + year level + semester.
     * Returns null if no preset exists for that combination.
     *
     * Used by AssessmentService and StudentFeeController to auto-populate
     * unit counts when creating a regular student assessment.
     */
    public static function forCourseYearSem(string $course, string $yearLevel, string $semester): ?self
    {
        return self::where('course', $course)
            ->where('year_level', $yearLevel)
            ->where('semester', $semester)
            ->where('is_active', true)
            ->first();
    }
}