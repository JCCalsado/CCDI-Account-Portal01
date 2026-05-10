<?php
namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CurriculumFeePresetSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('curriculum_fee_presets')->truncate();
        $now = now();

        // Columns: course, year_level, semester, lec_units, lab_units, lab_subjects, total_units, has_nstp
        // lec_units  = billable lecture units (NSTP and PATHFIT excluded)
        // lab_units  = total lab units (display only)
        // lab_subjects = number of subjects with lab (each adds lab_fee_per_subject)
        // total_units = lec + lab (display only)
        // has_nstp   = true if NSTP is in this semester (billed at fixed 1.5 units = ₱546)

        $presets = [

            // ── Associate in Computer Technology - Networking ─────────────────
            ['Associate in Computer Technology - Networking','1st Year','1st Sem', 17, 4, 4, 21, true ],
            ['Associate in Computer Technology - Networking','1st Year','2nd Sem', 17, 4, 4, 21, true ],
            ['Associate in Computer Technology - Networking','2nd Year','1st Sem', 20, 3, 3, 23, false],
            ['Associate in Computer Technology - Networking','2nd Year','2nd Sem', 18, 3, 3, 21, false],

            // ── BS Computer Science ───────────────────────────────────────────
            ['BS Computer Science','1st Year','1st Sem', 15, 3, 3, 18, true ],
            ['BS Computer Science','1st Year','2nd Sem', 14, 4, 4, 18, true ],
            ['BS Computer Science','2nd Year','1st Sem', 19, 2, 2, 21, false],
            ['BS Computer Science','2nd Year','2nd Sem', 18, 3, 3, 21, false],
            ['BS Computer Science','3rd Year','1st Sem', 17, 4, 4, 21, false],
            ['BS Computer Science','3rd Year','2nd Sem', 17, 4, 4, 21, false],
            ['BS Computer Science','4th Year','1st Sem', 13, 5, 5, 18, false],
            ['BS Computer Science','4th Year','2nd Sem',  6, 3, 2,  9, false],

            // ── BS Information Technology ─────────────────────────────────────
            ['BS Information Technology','1st Year','1st Sem', 15, 3, 3, 18, true ],
            ['BS Information Technology','1st Year','2nd Sem', 14, 4, 4, 18, true ],
            ['BS Information Technology','2nd Year','1st Sem', 15, 3, 3, 18, false],
            ['BS Information Technology','2nd Year','2nd Sem', 15, 3, 3, 18, false],
            ['BS Information Technology','3rd Year','1st Sem', 18, 3, 3, 21, false],
            ['BS Information Technology','3rd Year','2nd Sem', 17, 4, 4, 21, false],
            ['BS Information Technology','4th Year','1st Sem', 14, 4, 4, 18, false],
            ['BS Information Technology','4th Year','2nd Sem',  6, 3, 2,  9, false],

            // ── BS Information Systems ────────────────────────────────────────
            ['BS Information Systems','1st Year','1st Sem', 15, 3, 3, 18, true ],
            ['BS Information Systems','1st Year','2nd Sem', 16, 2, 2, 18, true ],
            ['BS Information Systems','2nd Year','1st Sem', 18, 3, 3, 21, false],
            ['BS Information Systems','2nd Year','2nd Sem', 17, 1, 1, 18, false],
            ['BS Information Systems','3rd Year','1st Sem', 16, 2, 2, 18, false],

            // ── BS Engineering Technology - Electronics ────────────────────────
            ['BS Engineering Technology - Electronics','1st Year','1st Sem', 22, 3, 3, 25, true ],
            ['BS Engineering Technology - Electronics','1st Year','2nd Sem', 19, 5, 5, 24, true ],
            ['BS Engineering Technology - Electronics','2nd Year','1st Sem', 23, 3, 3, 26, false],
            ['BS Engineering Technology - Electronics','2nd Year','2nd Sem', 20, 3, 3, 23, false],
            ['BS Engineering Technology - Electronics','3rd Year','1st Sem', 23, 3, 3, 26, false],
            ['BS Engineering Technology - Electronics','3rd Year','2nd Sem', 20, 5, 4, 25, false],
            ['BS Engineering Technology - Electronics','4th Year','1st Sem', 16, 3, 2, 19, false],
            ['BS Engineering Technology - Electronics','4th Year','2nd Sem', 12, 0, 0, 12, false],

            // ── BS Engineering Technology - Electrical ────────────────────────
            ['BS Engineering Technology - Electrical','1st Year','1st Sem', 19, 2, 2, 21, true ],
            ['BS Engineering Technology - Electrical','1st Year','2nd Sem', 19, 3, 3, 22, true ],
            ['BS Engineering Technology - Electrical','2nd Year','1st Sem', 21, 3, 3, 24, false],
            ['BS Engineering Technology - Electrical','2nd Year','2nd Sem', 24, 4, 4, 28, false],
            ['BS Engineering Technology - Electrical','3rd Year','1st Sem', 22, 2, 2, 24, false],
            ['BS Engineering Technology - Electrical','3rd Year','2nd Sem', 21, 4, 4, 25, false],
            ['BS Engineering Technology - Electrical','4th Year','1st Sem', 17, 5, 4, 22, false],
            ['BS Engineering Technology - Electrical','4th Year','2nd Sem', 12, 0, 0, 12, false],
        ];

        foreach ($presets as $p) {
            DB::table('curriculum_fee_presets')->insert([
                'course'       => $p[0],
                'year_level'   => $p[1],
                'semester'     => $p[2],
                'lec_units'    => $p[3],
                'lab_units'    => $p[4],
                'lab_subjects' => $p[5],
                'total_units'  => $p[6],
                'has_nstp'     => $p[7],
                'created_at'   => $now,
                'updated_at'   => $now,
            ]);
        }

        $this->command->info('✅ CurriculumFeePresetSeeder: ' . count($presets) . ' presets inserted.');
    }
}
