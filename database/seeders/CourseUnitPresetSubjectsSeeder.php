<?php

namespace Database\Seeders;

use App\Models\CourseUnitPreset;
use App\Models\CourseUnitPresetSubject;
use App\Models\Subject;
use Illuminate\Database\Seeder;

/**
 * CourseUnitPresetSubjectsSeeder
 *
 * Links each CourseUnitPreset to its corresponding Subject rows based on
 * course/year_level/semester matching.
 *
 * For each preset:
 *  1. Find all active subjects with matching course/year_level/semester
 *  2. Calculate fees from config/fees.php rates
 *  3. Insert denormalized data into course_unit_preset_subjects
 *
 * Dependencies (strict order):
 *  - CourseUnitPresetsSeeder must run first  (creates preset shells — runs in DatabaseSeeder)
 *  - EnhancedSubjectSeeder must run first    (creates subject rows — runs in AcademicDataSeeder Step 1)
 *  - This seeder runs as AcademicDataSeeder Step 2
 */
class CourseUnitPresetSubjectsSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('🔗 Linking subjects to presets...');
        $this->command->newLine();

        // Clear existing links
        CourseUnitPresetSubject::truncate();

        // Read rates from config — correct keys per config/fees.php
        $tuitionRate      = (float) config('fees.tuition_per_lec_unit', 364.00);
        $labFeePerSubject = (float) config('fees.lab.per_subject', 1656.00); // ← was 'fees.lab.per_unit' (wrong key)

        $totalLinked = 0;
        $presets = CourseUnitPreset::where('is_active', true)
            ->orderBy('course')
            ->orderBy('year_level')
            ->orderBy('semester')
            ->get();

        foreach ($presets as $preset) {
            // Find all subjects matching this preset's course/year/semester
            $subjects = Subject::where('course', $preset->course)
                ->where('year_level', $preset->year_level)
                ->where('semester', $preset->semester)
                ->where('is_active', true)
                ->orderBy('code')
                ->get();

            $sortOrder = 0;
            foreach ($subjects as $subject) {
                // Use the authoritative is_nstp flag from the subjects table.
                // This was set by EnhancedSubjectSeeder via the is_nstp column
                // (migration 2026_05_30_194854_add_is_nstp_to_subjects_table).
                $isNstp = (bool) $subject->is_nstp;

                // PATHFIT/PE detection — local variable only.
                // is_pathfit was dropped from course_unit_preset_subjects by migration
                // 2026_05_30_194939_drop_is_pathfit_from_course_unit_preset_subjects.
                // We still need this flag to correctly compute lab_fee (PATHFIT subjects
                // have lab_units > 0 but do NOT incur a lab fee per-subject).
                // It is used here for calculation only — NOT inserted into the DB.
                $isPathfit = stripos($subject->code, 'pathfit') !== false
                          || stripos($subject->code, 'pe ') === 0;

                // NSTP: billed separately at NSTP_MINIMUM_UNITS override — no tuition here
                $tuitionFee = $isNstp ? 0 : ($subject->lec_units * $tuitionRate);

                // Lab fee: per-subject flat rate when lab_units > 0, except PATHFIT/PE.
                // PATHFIT has a non-zero lab_units for timetabling but is not lab-billed.
                $labFee = ($subject->lab_units > 0 && !$isPathfit) ? $labFeePerSubject : 0;

                CourseUnitPresetSubject::create([
                    'course_unit_preset_id' => $preset->id,
                    'subject_id'            => $subject->id,
                    'lec_units'             => $subject->lec_units,
                    'lab_units'             => $subject->lab_units,
                    'tuition_fee'           => $tuitionFee,
                    'lab_fee'               => $labFee,
                    'total_fee'             => $tuitionFee + $labFee,
                    'is_nstp'               => $isNstp,
                    // is_pathfit intentionally omitted — column dropped from this table.
                    'sort_order'            => $sortOrder++,
                ]);

                $totalLinked++;
            }

            $this->command->info(
                "  ✓ {$preset->course} ({$preset->year_level} {$preset->semester}) — "
                . $subjects->count() . ' subjects'
            );
        }

        $this->command->newLine();
        $this->command->info("✅ CourseUnitPresetSubjectsSeeder: {$totalLinked} subject links inserted.");
        $this->command->newLine();

        $presetCount = CourseUnitPreset::where('is_active', true)->count();
        $this->command->table(
            ['Metric', 'Value'],
            [
                ['Total Presets',               $presetCount],
                ['Total Subject Links',         $totalLinked],
                ['Average Subjects per Preset', number_format($totalLinked / max($presetCount, 1), 1)],
                ['Tuition Rate (₱/lec_unit)',   '₱' . number_format($tuitionRate, 2)],
                ['Lab Fee (₱/subject)',         '₱' . number_format($labFeePerSubject, 2)],
            ]
        );
    }
}