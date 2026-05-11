<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * ComprehensiveUserSeeder
 *
 * Seeds exactly 100 students + 1 admin + 1 accounting staff.
 *
 * Distribution (all enrolled in AY 2025-2026 simultaneously):
 *   - 25 Active    → 1st Year  (current sem: 1Y-1S  in 2025-2026)
 *   - 25 Active    → 2nd Year  (current sem: 2Y-1S  in 2025-2026)
 *   - 20 Active    → 3rd Year  (current sem: 3Y-1S  in 2025-2026)
 *   - 10 Dropped   → 3rd Year  (current sem: 3Y-1S  in 2025-2026)
 *   - 10 Active    → 4th Year  (current sem: 4Y-1S  in 2025-2026)
 *   - 10 Graduated → 4th Year  (all sems fully paid)
 *
 * Discount overrides (resolved in ComprehensiveAssessmentSeeder):
 *   student1@ccdi.edu.ph  → Maria Santos  → discount_type = 'full'
 *   student2@ccdi.edu.ph  → Ana Garcia    → discount_type = 'nstp'
 *   student3–100          →               → discount_type = 'none'
 */
class ComprehensiveUserSeeder extends Seeder
{
    private int $accountNumberCounter = 0;

    private array $lastNames = [
        'Dela Cruz', 'Santos', 'Reyes', 'Garcia', 'Ramos',
        'Mendoza', 'Torres', 'Flores', 'Gonzales', 'Castro',
        'Rivera', 'Bautista', 'Santiago', 'Fernandez', 'Lopez',
        'Morales', 'Aquino', 'Villanueva', 'Cruz', 'Jimenez',
        'Martinez', 'Rodriguez', 'Hernandez', 'Perez', 'Gomez',
    ];

    private array $maleFirstNames = [
        'Juan', 'Jose', 'Pedro', 'Miguel', 'Carlos',
        'Antonio', 'Manuel', 'Francisco', 'Rafael', 'Eduardo',
        'Ricardo', 'Fernando', 'Roberto', 'Andres', 'Javier',
        'Rommel', 'Angelo', 'Danilo', 'Rodel', 'Marvin',
    ];

    private array $femaleFirstNames = [
        'Carmen', 'Rosa', 'Teresa', 'Elena', 'Isabel',
        'Lucia', 'Sofia', 'Patricia', 'Angela', 'Monica',
        'Gloria', 'Diana', 'Cristina', 'Rowena', 'Lourdes',
        'Jennelyn', 'Maribel', 'Charisma', 'Lovely', 'Maricel',
    ];

    private array $middleInitials = [
        'A', 'B', 'C', 'D', 'E', 'F', 'G',
        'H', 'J', 'K', 'L', 'M', 'N', 'P',
        'R', 'S', 'T', 'V',
    ];

    /**
     * Decomposed address seeds.
     * Using realistic Sorsogon province barangay/municipality combinations.
     */
    private array $addressSeeds = [
        ['barangay' => 'Barangay Ester',     'municipality' => 'Sorsogon City',  'province' => 'Sorsogon'],
        ['barangay' => 'Barangay Bibincahan', 'municipality' => 'Sorsogon City',  'province' => 'Sorsogon'],
        ['barangay' => 'Barangay Bitan-o',   'municipality' => 'Sorsogon City',  'province' => 'Sorsogon'],
        ['barangay' => 'Barangay Almendras',  'municipality' => 'Sorsogon City',  'province' => 'Sorsogon'],
        ['barangay' => 'Barangay Cahiton',    'municipality' => 'Bulan',          'province' => 'Sorsogon'],
        ['barangay' => 'Barangay Centro',     'municipality' => 'Irosin',         'province' => 'Sorsogon'],
        ['barangay' => 'Barangay Balogo',     'municipality' => 'Gubat',          'province' => 'Sorsogon'],
        ['barangay' => 'Barangay Bolos',      'municipality' => 'Castilla',       'province' => 'Sorsogon'],
        ['barangay' => 'Barangay Cogon',      'municipality' => 'Pilar',          'province' => 'Sorsogon'],
        ['barangay' => 'Barangay Gatbo',      'municipality' => 'Bacon District', 'province' => 'Sorsogon'],
        ['barangay' => 'Barangay Almeda',     'municipality' => 'Legazpi City',   'province' => 'Albay'],
        ['barangay' => 'Barangay Taysan',     'municipality' => 'Naga City',      'province' => 'Camarines Sur'],
        ['barangay' => 'Barangay Mabolo',     'municipality' => 'Daet',           'province' => 'Camarines Norte'],
    ];

    private array $courses = [
        'BET Electronics Engineering Technology',
        'BET Electrical Engineering Technology',
        'BS Information Technology',
        'BS Information Systems',
        'BS Computer Science',
        'Associate in Computer Technology - Networking',
        'Associate in Computer Technology - Programming',
        'Associate in Computer Technology - Multimedia/Animation',
        'Diploma in Software Development and Programming',
        'Diploma in Electronics and Computer Technology',
    ];

    // =========================================================================

    public function run(): void
    {
        // Wipe previous student batch; preserve admin and accounting
        Student::whereHas('user', fn ($q) => $q->where('role', 'student'))->delete();
        User::where('role', 'student')->delete();

        // Admin
        $admin = User::firstOrCreate(
            ['email' => 'admin@ccdi.edu.ph'],
            [
                'last_name'                => 'Rodriguez',
                'first_name'               => 'Carlos',
                'middle_initial'           => 'M',
                'password'                 => Hash::make('password'),
                'role'                     => 'admin',
                'status'                   => User::STATUS_ACTIVE,
                'faculty'                  => 'Administration',
                'phone'                    => '09171234501',
                'address_house_lot_unit'   => null,
                'address_street_name'      => 'Magsaysay Street',
                'address_barangay'         => 'Barangay Ester',
                'address_municipality_city'=> 'Sorsogon City',
                'address_province'         => 'Sorsogon',
                'birthday'                 => '1985-05-15',
            ]
        );
        $admin->account()->firstOrCreate([], ['balance' => 0]);

        // Accounting
        $accounting = User::firstOrCreate(
            ['email' => 'accounting@ccdi.edu.ph'],
            [
                'last_name'                => 'Garcia',
                'first_name'               => 'Ana Marie',
                'middle_initial'           => 'S',
                'password'                 => Hash::make('password'),
                'role'                     => 'accounting',
                'status'                   => User::STATUS_ACTIVE,
                'faculty'                  => 'Accounting Department',
                'phone'                    => '09181234502',
                'address_house_lot_unit'   => null,
                'address_street_name'      => 'Penaranda Street',
                'address_barangay'         => 'Barangay Bibincahan',
                'address_municipality_city'=> 'Sorsogon City',
                'address_province'         => 'Sorsogon',
                'birthday'                 => '1990-08-20',
            ]
        );
        $accounting->account()->firstOrCreate([], ['balance' => 0]);

        // ── Slots 0–1: discount students (always locked at front) ──────────────
        $blueprint = [
            ['year_level' => '1st Year', 'status' => 'active',    'balance' => 0],      // student1 → full discount
            ['year_level' => '1st Year', 'status' => 'active',    'balance' => 0],      // student2 → nstp discount
        ];

        $pool = [];

        for ($i = 0; $i < 23; $i++) {
            $pool[] = ['year_level' => '1st Year', 'status' => 'active',    'balance' => rand(5000, 15000)];
        }
        for ($i = 0; $i < 25; $i++) {
            $pool[] = ['year_level' => '2nd Year', 'status' => 'active',    'balance' => rand(3000, 12000)];
        }
        for ($i = 0; $i < 20; $i++) {
            $pool[] = ['year_level' => '3rd Year', 'status' => 'active',    'balance' => rand(3000, 10000)];
        }
        for ($i = 0; $i < 10; $i++) {
            $pool[] = ['year_level' => '3rd Year', 'status' => 'dropped',   'balance' => rand(5000, 20000)];
        }
        for ($i = 0; $i < 10; $i++) {
            $pool[] = ['year_level' => '4th Year', 'status' => 'active',    'balance' => rand(1000, 5000)];
        }
        for ($i = 0; $i < 10; $i++) {
            $pool[] = ['year_level' => '4th Year', 'status' => 'graduated', 'balance' => 0];
        }

        shuffle($pool);
        $blueprint = array_merge($blueprint, $pool);

        $userStatusMap = [
            'active'    => User::STATUS_ACTIVE,
            'dropped'   => User::STATUS_DROPPED,
            'graduated' => User::STATUS_GRADUATED,
        ];

        $enrollmentStatusMap = [
            'active'    => 'enrolled',
            'dropped'   => 'inactive',
            'graduated' => 'graduated',
        ];

        foreach ($blueprint as $index => $slot) {
            $studentNumber = $index + 1;
            $studentId     = '2025-' . str_pad($studentNumber, 4, '0', STR_PAD_LEFT);
            $email         = "student{$studentNumber}@ccdi.edu.ph";

            if ($studentNumber === 1) {
                $firstName = 'Maria';
                $lastName  = 'Santos';
            } elseif ($studentNumber === 2) {
                $firstName = 'Ana';
                $lastName  = 'Garcia';
            } else {
                $isFemale  = ($studentNumber % 2 === 0);
                $firstName = $isFemale
                    ? $this->femaleFirstNames[array_rand($this->femaleFirstNames)]
                    : $this->maleFirstNames[array_rand($this->maleFirstNames)];
                $lastName  = $this->lastNames[array_rand($this->lastNames)];
            }

            $middleInitial = $this->middleInitials[array_rand($this->middleInitials)];
            $addressSeed   = $this->addressSeeds[$index % count($this->addressSeeds)];
            $course        = $this->courses[$index % count($this->courses)];

            $yearLevelNum = (int) substr($slot['year_level'], 0, 1);
            $birthYear    = 2025 - 18 - ($yearLevelNum - 1);
            $birthday     = $birthYear
                . '-' . str_pad(rand(1, 12), 2, '0', STR_PAD_LEFT)
                . '-' . str_pad(rand(1, 28), 2, '0', STR_PAD_LEFT);

            $user = User::create([
                'last_name'                => $lastName,
                'first_name'               => $firstName,
                'middle_initial'           => $middleInitial,
                'email'                    => $email,
                'password'                 => Hash::make('password'),
                'role'                     => 'student',
                'account_id'               => $studentId,
                'status'                   => $userStatusMap[$slot['status']],
                'course'                   => $course,
                'year_level'               => $slot['year_level'],
                'birthday'                 => $birthday,
                'phone'                    => '0917' . rand(1000000, 9999999),
                'address_house_lot_unit'   => null,
                'address_street_name'      => null,
                'address_barangay'         => $addressSeed['barangay'],
                'address_municipality_city'=> $addressSeed['municipality'],
                'address_province'         => $addressSeed['province'],
            ]);

            $user->account()->create([
                'account_number' => $this->nextAccountNumber(),
                'balance'        => -$slot['balance'],
            ]);

            Student::create([
                'user_id'           => $user->id,
                'student_id'        => $studentId,
                'enrollment_status' => $enrollmentStatusMap[$slot['status']],
            ]);
        }

        $this->command->info('✓ 100 students seeded.');
        $this->command->table(
            ['Year Level', 'Count', 'Status'],
            [
                ['1st Year',  25, 'active (2 with discounts)'],
                ['2nd Year',  25, 'active'],
                ['3rd Year',  20, 'active'],
                ['3rd Year',  10, 'dropped'],
                ['4th Year',  10, 'active'],
                ['4th Year',  10, 'graduated'],
            ]
        );
        $this->command->info('  student1 → Maria Santos  (full discount)');
        $this->command->info('  student2 → Ana Garcia    (nstp discount)');
        $this->command->info('  All passwords: password');
    }

    private function nextAccountNumber(): string
    {
        $year = now()->year;

        if ($this->accountNumberCounter === 0) {
            $last = Account::where('account_number', 'like', "ACC-{$year}-%")
                ->orderByRaw('CAST(SUBSTRING(account_number, 10) AS UNSIGNED) DESC')
                ->first();

            $this->accountNumberCounter = $last
                ? (int) substr($last->account_number, -4)
                : 0;
        }

        return 'ACC-' . $year . '-' . str_pad(++$this->accountNumberCounter, 4, '0', STR_PAD_LEFT);
    }
}