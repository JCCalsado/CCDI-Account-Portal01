<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drop has_nstp column from course_unit_presets.
 *
 * PURPOSE:
 *   has_nstp was a cached aggregate flag on the preset that mirrored whether
 *   any of its linked subjects were NSTP subjects. It was maintained by
 *   PresetSubjectController::syncPresetAggregates() and read by
 *   AssessmentService for billing decisions.
 *
 *   This column is now redundant because:
 *     1. AssessmentService reads subject->is_nstp directly (per-subject flag).
 *     2. The system no longer needs a preset-level NSTP aggregate — NSTP
 *        detection happens at the subject level during assessment computation.
 *     3. syncPresetAggregates() will be updated to stop writing has_nstp.
 *
 * SAFE TO DROP:
 *   - AssessmentService::compute() takes $nstpLecUnits as a computed parameter,
 *     not a preset column lookup. This column was never the direct input.
 *   - CourseUnitPreset::forCourseYearSem() does not read has_nstp.
 *   - No Vue component reads has_nstp directly from the preset object for
 *     billing calculations — only for display (now replaced by subject badges).
 *
 * ALSO DROPS: the migration-added is_active column is intentionally kept.
 *   Only has_nstp is removed here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('course_unit_presets', function (Blueprint $table) {
            $table->dropColumn('has_nstp');
        });
    }

    public function down(): void
    {
        Schema::table('course_unit_presets', function (Blueprint $table) {
            $table->boolean('has_nstp')
                ->default(false)
                ->after('lab_subject_count')
                ->comment('Restored by rollback — was dropped in favour of per-subject is_nstp flag.');
        });
    }
};