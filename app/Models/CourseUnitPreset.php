<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CourseUnitPreset extends Model
{
    protected $table = 'course_unit_presets';

    protected $fillable = [
        'course', 'year_level', 'semester',
        'lec_units', 'lab_units', 'lab_subject_count',
        'has_nstp', 'is_active',
    ];

    protected $casts = [
        'lec_units'         => 'integer',
        'lab_units'         => 'integer',
        'lab_subject_count' => 'integer',
        'has_nstp'          => 'boolean',
        'is_active'         => 'boolean',
    ];

    // ─── Relationships ────────────────────────────────────────────────────────

    /**
     * The subjects that make up this preset's curriculum.
     * One subject can only appear once per preset (unique constraint on table).
     * Ordered by sort_order so the UI displays subjects in a consistent sequence.
     */
    public function presetSubjects(): HasMany
    {
        return $this->hasMany(CourseUnitPresetSubject::class, 'course_unit_preset_id')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    // ─── Static Methods ───────────────────────────────────────────────────────

    public static function supportedCourses(): array
    {
        return self::where('is_active', true)
            ->distinct()->orderBy('course')->pluck('course')->toArray();
    }

    public static function forCourseYearSem(string $course, string $yearLevel, string $semester): ?self
    {
        return self::where('course', $course)
            ->where('year_level', $yearLevel)
            ->where('semester', $semester)
            ->where('is_active', true)
            ->first();
    }
}