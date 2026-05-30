<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drop is_pathfit column from course_unit_preset_subjects.
 *
 * PURPOSE:
 *   PATHFIT subjects are billed identically to regular subjects at CCDI.
 *   The is_pathfit flag was a display-only classification with no billing
 *   impact. It was populated via fragile string-sniff detection
 *   (AssessmentService::isPathfitSubject) and served no functional purpose.
 *
 *   Removing it eliminates dead data, removes a misleading column, and
 *   simplifies the preset subject management code.
 *
 * SAFE TO DROP:
 *   - No billing logic reads is_pathfit from this table.
 *   - PresetSubjectController wrote it for display only.
 *   - No foreign key constraints on this column.
 *   - assessment_subjects.is_pathfit is intentionally kept — it is a
 *     historical billing snapshot and must not be altered retroactively.
 *
 * NOTE ON assessment_subjects.is_pathfit:
 *   That column is NOT dropped here. Historical assessment snapshots are
 *   immutable. Going forward, buildSubjectSnapshot() writes is_pathfit = false
 *   for all new assessments (PATHFIT is just a regular subject).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('course_unit_preset_subjects', function (Blueprint $table) {
            $table->dropColumn('is_pathfit');
        });
    }

    public function down(): void
    {
        Schema::table('course_unit_preset_subjects', function (Blueprint $table) {
            $table->boolean('is_pathfit')
                ->default(false)
                ->after('is_nstp')
                ->comment('Restored by rollback — was dropped in favour of uniform billing.');
        });
    }
};