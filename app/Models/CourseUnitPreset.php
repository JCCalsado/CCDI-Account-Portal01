<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CourseUnitPreset extends Model
{
    protected $table = 'course_unit_presets';

    protected $fillable = [
        'course', 'year_level', 'semester',
        'lec_units', 'lab_units', 'lab_subject_count', 'is_active',
    ];

    protected $casts = [
        'lec_units'         => 'integer',
        'lab_units'         => 'integer',
        'lab_subject_count' => 'integer',
        'is_active'         => 'boolean',
    ];

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
