<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CourseUnitPresetsSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('course_unit_presets')->truncate();
        $now = now();

        // [course, year_level, semester, lec_units, lab_units, lab_subject_count]
        $presets = [
            // ── Associate in Computer Technology - Networking ─────────────────
            ['Associate in Computer Technology - Networking', '1st Year', '1st Sem', 17, 4, 4],
            ['Associate in Computer Technology - Networking', '1st Year', '2nd Sem', 17, 4, 4],
            ['Associate in Computer Technology - Networking', '2nd Year', '1st Sem', 20, 3, 3],
            ['Associate in Computer Technology - Networking', '2nd Year', '2nd Sem', 13, 2, 2],
            ['Associate in Computer Technology - Networking', '3rd Year', '1st Sem',  0, 0, 0],
            ['Associate in Computer Technology - Networking', '3rd Year', '2nd Sem',  0, 0, 0],
            ['Associate in Computer Technology - Networking', '4th Year', '1st Sem',  0, 0, 0],
            ['Associate in Computer Technology - Networking', '4th Year', '2nd Sem',  0, 0, 0],

            // ── BS Computer Science ───────────────────────────────────────────
            ['BS Computer Science', '1st Year', '1st Sem', 15, 3, 3],
            ['BS Computer Science', '1st Year', '2nd Sem', 14, 4, 4],
            ['BS Computer Science', '2nd Year', '1st Sem', 19, 2, 2],
            ['BS Computer Science', '2nd Year', '2nd Sem', 18, 3, 3],
            ['BS Computer Science', '3rd Year', '1st Sem', 17, 4, 4],
            ['BS Computer Science', '3rd Year', '2nd Sem', 17, 4, 4],
            ['BS Computer Science', '4th Year', '1st Sem', 13, 5, 5],
            ['BS Computer Science', '4th Year', '2nd Sem',  2, 1, 1],

            // ── BS Information Technology ─────────────────────────────────────
            ['BS Information Technology', '1st Year', '1st Sem', 15, 3, 3],
            ['BS Information Technology', '1st Year', '2nd Sem', 14, 4, 4],
            ['BS Information Technology', '2nd Year', '1st Sem', 15, 3, 3],
            ['BS Information Technology', '2nd Year', '2nd Sem', 15, 3, 3],
            ['BS Information Technology', '3rd Year', '1st Sem', 18, 3, 3],
            ['BS Information Technology', '3rd Year', '2nd Sem', 17, 4, 4],
            ['BS Information Technology', '4th Year', '1st Sem', 14, 4, 4],
            ['BS Information Technology', '4th Year', '2nd Sem',  2, 1, 1],

            // ── BS Information Systems ────────────────────────────────────────
            ['BS Information Systems', '1st Year', '1st Sem', 17, 3, 3],
            ['BS Information Systems', '1st Year', '2nd Sem', 18, 2, 2],
            ['BS Information Systems', '2nd Year', '1st Sem', 20, 3, 3],
            ['BS Information Systems', '2nd Year', '2nd Sem', 17, 1, 1],
            ['BS Information Systems', '3rd Year', '1st Sem', 16, 2, 2],
            ['BS Information Systems', '3rd Year', '2nd Sem',  0, 0, 0],
            ['BS Information Systems', '4th Year', '1st Sem',  0, 0, 0],
            ['BS Information Systems', '4th Year', '2nd Sem',  0, 0, 0],

            // ── BS Engineering Technology - Electronics ───────────────────────
            ['BS Engineering Technology - Electronics', '1st Year', '1st Sem', 22, 3, 3],
            ['BS Engineering Technology - Electronics', '1st Year', '2nd Sem', 19, 5, 5],
            ['BS Engineering Technology - Electronics', '2nd Year', '1st Sem', 23, 3, 3],
            ['BS Engineering Technology - Electronics', '2nd Year', '2nd Sem', 20, 3, 3],
            ['BS Engineering Technology - Electronics', '3rd Year', '1st Sem', 23, 3, 3],
            ['BS Engineering Technology - Electronics', '3rd Year', '2nd Sem', 20, 4, 4],
            ['BS Engineering Technology - Electronics', '4th Year', '1st Sem', 16, 2, 2],
            ['BS Engineering Technology - Electronics', '4th Year', '2nd Sem', 12, 0, 0],

            // ── BS Engineering Technology - Electrical ────────────────────────
            ['BS Engineering Technology - Electrical', '1st Year', '1st Sem', 19, 2, 2],
            ['BS Engineering Technology - Electrical', '1st Year', '2nd Sem', 19, 3, 3],
            ['BS Engineering Technology - Electrical', '2nd Year', '1st Sem', 21, 3, 3],
            ['BS Engineering Technology - Electrical', '2nd Year', '2nd Sem', 24, 4, 4],
            ['BS Engineering Technology - Electrical', '3rd Year', '1st Sem', 22, 2, 2],
            ['BS Engineering Technology - Electrical', '3rd Year', '2nd Sem', 21, 4, 4],
            ['BS Engineering Technology - Electrical', '4th Year', '1st Sem', 17, 4, 4],
            ['BS Engineering Technology - Electrical', '4th Year', '2nd Sem', 12, 0, 0],
        ];

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

        DB::table('course_unit_presets')->insert($rows);
        $this->command->info('✅ course_unit_presets seeded (' . count($rows) . ' rows).');
    }
}
