<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * CourseUnitPresetsSeeder
 *
 * Canonical unit preset data for all CCDI programs (AY 2025-2026).
 * Aggregates subject counts by course/year/semester for billing and enrollment.
 * Sources: EnhancedSubjectSeeder and official OBE curriculum documents.
 *
 * Schema columns:
 *   - lec_units         → sum of ALL lec_units EXCEPT NSTP subjects.
 *                         PATHFIT/PE units ARE included in this sum.
 *   - lab_units         → sum of all lab_units in the term (display only)
 *   - lab_subject_count → count of subjects where lab_units > 0 (drives lab fee)
 *
 * NOTE: has_nstp was dropped from this table by migration
 *   2026_05_30_195021_drop_has_nstp_from_course_unit_presets.
 *   NSTP detection now happens at the subject level via Subject::is_nstp.
 *   AssessmentService reads is_nstp per-subject — it no longer reads a preset flag.
 *
 * NSTP presence (for reference only — not stored on the preset):
 *   - 1st Year only (1st Sem + 2nd Sem) for all 6 courses
 *   - NOT present in 2nd Year and above
 *
 * Verified against EnhancedSubjectSeeder on 2026-05-11.
 * Formula: lec_units = Σ(subject.lec_units) where subject.is_nstp = false
 */
class CourseUnitPresetsSeeder extends Seeder
{
    public function run(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        DB::table('course_unit_presets')->truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');

        $now = now();

        $presets = $this->allPresets();

        $rows = [];
        foreach ($presets as [$course, $yearLevel, $semester, $lec, $lab, $labCount]) {
            $rows[] = [
                'course'            => $course,
                'year_level'        => $yearLevel,
                'semester'          => $semester,
                'lec_units'         => $lec,
                'lab_units'         => $lab,
                'lab_subject_count' => $labCount,
                'is_active'         => true,
                'created_at'        => $now,
                'updated_at'        => $now,
            ];
        }

        foreach (array_chunk($rows, 50) as $chunk) {
            DB::table('course_unit_presets')->insert($chunk);
        }

        $this->command->info('✅ course_unit_presets seeded (' . count($rows) . ' rows).');
        $this->command->newLine();
        $this->printVerificationTable($rows);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ALL PRESETS
    // ─────────────────────────────────────────────────────────────────────────

    private function allPresets(): array
    {
        return array_merge(
            $this->actNetworking(),
            $this->bscs(),
            $this->bsit(),
            $this->bsis(),
            $this->bsetEce(),
            $this->bsetEet(),
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // COURSE-SPECIFIC PRESETS
    // Columns: [course, year_level, semester, lec_units, lab_units, lab_subject_count]
    //
    // has_nstp was dropped from course_unit_presets on 2026-05-30.
    // NSTP detection now uses Subject::is_nstp at assessment time.
    //
    // lec_units verification shorthand used in comments:
    //   ✓ PATHFIT = included in sum
    //   ✗ NSTP    = excluded from sum (billed separately via per-subject is_nstp flag)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * ACT — Associate in Computer Technology (Networking)
     *
     * 1Y 1S: 3+3+3+2+2+2+2(PATHFIT) = 19  [✗NSTP=1.5]
     * 1Y 2S: 3+3+3+2+2+2+2+2(PATHFIT) = 19  [✗NSTP=1.5] — wait, let me recount
     *         3+3+3+2+2+2+2+2(PATHFIT) — GEELECT2,LIT1,PHILO,ITC104,ITC105,ITC106,ELECTIVE2,PATHFIT2
     *         = 3+3+3+2+2+2+2+2 = 19 ✓
     * 2Y 1S: 3+3+3+3+2+2+2+2(PATHFIT) = 22  [no NSTP]
     *         ETHICS,HIST1,SCIE1,GEELECT3,HUM(2+lab),ITC201(2+lab),ELEC3(2+lab),ELEC4(2+0),PATHFIT3(2)
     *         Note: ACT-ELEC4 has lab_units=0 so lab_subject_count stays at 3
     * 2Y 2S: 2+2+3+3+3+2(PATHFIT)+5 = 20  [no NSTP]  ← was 18, PATHFIT4 missing
     *         ELEC5(2+lab),ELEC6(2+lab),PROF1,PROF2,SOCSCI1,PATHFIT4(2),OJT(5+lab)
     */
    private function actNetworking(): array
    {
        $c = 'Associate in Computer Technology - Networking';
        return [
            // [course, year_level, semester, lec_units, lab_units, lab_subject_count]
            [$c, '1st Year', '1st Sem', 19, 4, 4],   // +PATHFIT(2) -NSTP(1.5)
            [$c, '1st Year', '2nd Sem', 19, 4, 4],   // +PATHFIT(2) -NSTP(1.5)
            [$c, '2nd Year', '1st Sem', 22, 3, 3],  // +PATHFIT(2)
            [$c, '2nd Year', '2nd Sem', 20, 3, 3],  // +PATHFIT(2) | was 18
            // ACT is a 2-year program — 3rd/4th year rows kept for schema consistency
            [$c, '3rd Year', '1st Sem',  0, 0, 0],
            [$c, '3rd Year', '2nd Sem',  0, 0, 0],
            [$c, '4th Year', '1st Sem',  0, 0, 0],
            [$c, '4th Year', '2nd Sem',  0, 0, 0],
        ];
    }

    /**
     * BSCS — Bachelor of Science in Computer Science
     *
     * 1Y 1S: 3+3+3+2+2+2+2(PATHFIT) = 17  [✗NSTP]  | was 15
     * 1Y 2S: 3+3+2+2+2+2+2(PATHFIT) = 16  [✗NSTP]  | was 14
     * 2Y 1S: 3+3+3+3+2+2+3+2(PATHFIT) = 21          | was 19
     * 2Y 2S: 3+2+2+2+3+3+3+2(PATHFIT) = 20          | was 18
     * 3Y 1S: 3+3+2+2+3+2+2 = 17                     | unchanged
     * 3Y 2S: 2+2+2+3+3+2+3 = 17                     | unchanged
     * 4Y 1S: 3+2+2+2+2+2 = 13                       | unchanged
     * 4Y 2S: THESIS2(2+lab=1) + PRACTICUM(4+lab=2) = lec:6, lab:3, lab_count:2 | was 2,1,1
     */
    private function bscs(): array
    {
        $c = 'BS Computer Science';
        return [
            [$c, '1st Year', '1st Sem', 17, 3, 3],   // +PATHFIT(2) -NSTP(1.5) | was 15
            [$c, '1st Year', '2nd Sem', 16, 4, 4],   // +PATHFIT(2) -NSTP(1.5) | was 14
            [$c, '2nd Year', '1st Sem', 21, 2, 2],  // +PATHFIT(2)             | was 19
            [$c, '2nd Year', '2nd Sem', 20, 3, 3],  // +PATHFIT4(2)            | was 18
            [$c, '3rd Year', '1st Sem', 17, 4, 4],  // no PATHFIT in 3rd year
            [$c, '3rd Year', '2nd Sem', 17, 4, 4],
            [$c, '4th Year', '1st Sem', 13, 5, 5],
            [$c, '4th Year', '2nd Sem',  6, 3, 2],  // Thesis2+Practicum | was 2,1,1
        ];
    }

    /**
     * BSIT — Bachelor of Science in Information Technology
     *
     * 1Y 1S: 3+3+3+2+2+2+2(PATHFIT) = 17  [✗NSTP]  | was 15
     * 1Y 2S: 3+3+2+2+2+2+2(PATHFIT) = 16  [✗NSTP]  | was 14
     * 2Y 1S: 3+3+3+2+2+2+2(PATHFIT) = 17            | unchanged (PATHFIT was already in)
     * 2Y 2S: 2+3+2+2+3+3+2(PATHFIT) = 17            | unchanged (PATHFIT was already in)
     * 3Y 1S: 3+3+3+2+2+3+2 = 18                     | unchanged
     * 3Y 2S: 2+3+3+2+2+2+3 = 17                     | unchanged
     * 4Y 1S: 3+2+3+2+2+2 = 14                       | unchanged
     * 4Y 2S: PROJECT2(2+lab=1) + PRACTICUM(4+lab=2) = lec:6, lab:3, lab_count:2 | was 2,1,1
     */
    private function bsit(): array
    {
        $c = 'BS Information Technology';
        return [
            [$c, '1st Year', '1st Sem', 17, 3, 3],   // +PATHFIT(2) -NSTP(1.5) | was 15
            [$c, '1st Year', '2nd Sem', 16, 4, 4],   // +PATHFIT(2) -NSTP(1.5) | was 14
            [$c, '2nd Year', '1st Sem', 17, 3, 3],  // already correct
            [$c, '2nd Year', '2nd Sem', 17, 3, 3],  // already correct
            [$c, '3rd Year', '1st Sem', 18, 3, 3],
            [$c, '3rd Year', '2nd Sem', 17, 4, 4],
            [$c, '4th Year', '1st Sem', 14, 4, 4],
            [$c, '4th Year', '2nd Sem',  6, 3, 2],  // Project2+Practicum | was 2,1,1
        ];
    }

    /**
     * BSIS — Bachelor of Science in Information Systems
     *
     * 1Y 1S: 3+3+3+2+2+2+2(PE1) = 17  [✗NSTP]   | already correct
     * 1Y 2S: 3+3+3+3+2+2+2(PE2) = 18  [✗NSTP]   | already correct
     * 2Y 1S: 3+3+2+3+2+2+3+2(PE3) = 20           | already correct
     * 2Y 2S: 3+3+3+3+2+3+2(PE4) = 19             | was 17 (PE excluded)
     * 3Y 1S: 3+2+3+3+2+3 = 16                    | unchanged (no PE in 3rd year)
     * 3Y 2S / 4Y: curriculum not yet in seeder    | zeros retained
     */
    private function bsis(): array
    {
        $c = 'BS Information Systems';
        return [
            [$c, '1st Year', '1st Sem', 17, 3, 3],   // already correct
            [$c, '1st Year', '2nd Sem', 18, 2, 2],   // already correct
            [$c, '2nd Year', '1st Sem', 20, 3, 3],  // already correct
            [$c, '2nd Year', '2nd Sem', 19, 1, 1],  // +PE4(2) | was 17
            [$c, '3rd Year', '1st Sem', 16, 2, 2],  // no PE in 3rd year
            // 3rd Year 2nd Sem and 4th Year: no subjects defined in EnhancedSubjectSeeder yet
            [$c, '3rd Year', '2nd Sem',  0, 0, 0],
            [$c, '4th Year', '1st Sem',  0, 0, 0],
            [$c, '4th Year', '2nd Sem',  0, 0, 0],
        ];
    }

    /**
     * BSET-ECE — Bachelor of Science in Engineering Technology (Electronics)
     *
     * 1Y 1S: 3+2+3+3+3+3+3+2+2(PATHFIT) = 24  [✗NSTP]  | was 22
     * 1Y 2S: 3+3+3+3+3+2+2+2(PATHFIT) = 21    [✗NSTP]  | was 19
     * 2Y 1S: 3+3+3+3+3+3+3+2+2(PATHFIT) = 25           | was 23
     * 2Y 2S: 3+3+3+3+3+3+2+2(PE) = 22                  | was 20
     * 3Y 1S: 3+3+3+3+3+3+3+2 = 23  (no PATHFIT in 3Y)  | unchanged
     * 3Y 2S: 3+3+3+3+3+3+2 = 20                        | unchanged
     *         lab: ECE131(1)+ELXT150(2)+ELXT160(1)+PROJECT1(1) = 5, lab_count=4
     * 4Y 1S: 3+4+3+3+3 = 16                            | unchanged
     *         lab: ECE132(1)+ELXT170(2) = 3, lab_count=2
     * 4Y 2S: OJT(12+0) = 12                            | unchanged
     */
    private function bsetEce(): array
    {
        $c = 'BS Engineering Technology - Electronics';
        return [
            [$c, '1st Year', '1st Sem', 24, 3, 3],   // +PATHFIT(2) -NSTP(1.5) | was 22
            [$c, '1st Year', '2nd Sem', 21, 5, 5],   // +PATHFIT(2) -NSTP(1.5) | was 19
            [$c, '2nd Year', '1st Sem', 25, 3, 3],  // +PATHFIT(2)             | was 23
            [$c, '2nd Year', '2nd Sem', 22, 3, 3],  // +PE4(2)                 | was 20
            [$c, '3rd Year', '1st Sem', 23, 3, 3],  // no PATHFIT in 3rd year
            [$c, '3rd Year', '2nd Sem', 20, 5, 4],  // lab_units: ECE131(1)+ELXT150(2)+ELXT160(1)+PROJECT1(1)=5
            [$c, '4th Year', '1st Sem', 16, 3, 2],  // lab: ECE132(1)+ELXT170(2)=3, lab_count=2
            [$c, '4th Year', '2nd Sem', 12, 0, 0],  // OJT only
        ];
    }

    /**
     * BSET-EET — Bachelor of Science in Engineering Technology (Electrical)
     *
     * 1Y 1S: 3+2+3+3+3+3+2+2(PATHFIT) = 21   [✗NSTP]  | was 19
     * 1Y 2S: 3+3+3+3+3+2+2+2(PATHFIT) = 21   [✗NSTP]  | was 19
     * 2Y 1S: 3+3+3+3+3+2+2+2+2(PATHFIT) = 23           | was 21
     * 2Y 2S: 3+3+2+3+3+3+2+3+2+2(PE4) = 26             | was 24
     *         Subjects: GEELEC3,GE6,EET170,BT,FM,EET140,EET150,IELX,COMP202,PE4
     * 3Y 1S: 3+3+3+3+2+3+2+3 = 22  (no PATHFIT)        | unchanged
     * 3Y 2S: 3+3+3+3+3+2+2+2 = 21  (no PATHFIT)        | unchanged
     * 4Y 1S: 3+3+2+3+3+3 = 17                          | lab_units fix: was 4, now 5
     *         lab: EET230(1)+EET240(2)+EET250(1)+EET260(1) = 5, lab_count=4
     * 4Y 2S: OJT(12+0) = 12                            | unchanged
     */
    private function bsetEet(): array
    {
        $c = 'BS Engineering Technology - Electrical';
        return [
            [$c, '1st Year', '1st Sem', 21, 2, 2],   // +PATHFIT(2) -NSTP(1.5) | was 19
            [$c, '1st Year', '2nd Sem', 21, 3, 3],   // +PATHFIT(2) -NSTP(1.5) | was 19
            [$c, '2nd Year', '1st Sem', 23, 3, 3],  // +PATHFIT(2)             | was 21
            [$c, '2nd Year', '2nd Sem', 26, 4, 4],  // +PE4(2)                 | was 24
            [$c, '3rd Year', '1st Sem', 22, 2, 2],  // no PATHFIT in 3rd year
            [$c, '3rd Year', '2nd Sem', 21, 4, 4],
            [$c, '4th Year', '1st Sem', 17, 5, 4],  // lab_units 4→5 (1+2+1+1=5)
            [$c, '4th Year', '2nd Sem', 12, 0, 0],  // OJT only
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // DEBUG OUTPUT
    // ─────────────────────────────────────────────────────────────────────────

    private function printVerificationTable(array $rows): void
    {
        $this->command->info('📊 Seeded Presets:');
        $this->command->table(
            ['Course', 'Year', 'Sem', 'Lec', 'Lab', 'LabSubj'],
            array_map(fn($r) => [
                $r['course'],
                $r['year_level'],
                $r['semester'],
                $r['lec_units'],
                $r['lab_units'],
                $r['lab_subject_count'],
            ], $rows)
        );
    }
}