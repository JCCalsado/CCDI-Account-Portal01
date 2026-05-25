<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\User;
use App\Models\Student;
use Illuminate\Support\Facades\Hash;

class ResetDemoUsers extends Command
{
    /**
     * The name and signature of the console command.
     *
     * Example: php artisan demo:reset
     *          php artisan demo:reset --with-students
     */
    protected $signature = 'demo:reset {--with-students : Reset demo students as well}';

    /**
     * The console command description.
     */
    protected $description = 'Reset demo admin, accounting, and optional demo students without overwriting real data.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Resetting demo users...');

        // Reset Admin
        $admin = User::updateOrCreate(
            ['email' => 'admin@ccdi.edu.ph'],
            [
                'last_name'                 => 'Rodriguez',
                'first_name'                => 'Carlos',
                'middle_initial'            => 'M',
                'password'                  => Hash::make('password'),
                'role'                      => 'admin',
                'status'                    => User::STATUS_ACTIVE,
                'faculty'                   => 'Administration',
                'phone'                     => '09171234501',
                'birthday'                  => '1985-05-15',
                'address_house_lot_unit'    => null,
                'address_street_name'       => null,
                'address_barangay'          => null,
                'address_municipality_city' => 'Sorsogon City',
                'address_province'          => 'Sorsogon',
            ]
        );
        $admin->account()->firstOrCreate([], ['balance' => 0]);
        $this->info('✓ Admin (Carlos M. Rodriguez) reset.');

        // Reset Accounting Staff
        $accounting = User::updateOrCreate(
            ['email' => 'accounting@ccdi.edu.ph'],
            [
                'last_name'                 => 'Garcia',
                'first_name'                => 'Ana Marie',
                'middle_initial'            => 'S',
                'password'                  => Hash::make('password'),
                'role'                      => 'accounting',
                'status'                    => User::STATUS_ACTIVE,
                'faculty'                   => 'Accounting Department',
                'phone'                     => '09181234502',
                'birthday'                  => '1990-08-20',
                'address_house_lot_unit'    => null,
                'address_street_name'       => null,
                'address_barangay'          => null,
                'address_municipality_city' => 'Legazpi City',
                'address_province'          => 'Albay',
            ]
        );
        $accounting->account()->firstOrCreate([], ['balance' => 0]);
        $this->info('✓ Accounting staff (Ana Marie S. Garcia) reset.');

        if ($this->option('with-students')) {
            $this->resetDemoStudents();
        } else {
            $this->warn('Skipping demo students. Use --with-students to reset them too.');
        }

        $this->info('✅ Demo users reset complete.');
        return self::SUCCESS;
    }

    private function resetDemoStudents(): void
    {
        $students = [
            [
                'last_name'                 => 'Dela Cruz',
                'first_name'                => 'Juan',
                'middle_initial'            => 'P',
                'email'                     => 'student1@ccdi.edu.ph',
                'student_id'                => '2025-0001',
                'course'                    => 'BS Computer Science',
                'year_level'                => '1st Year',
                'user_status'               => User::STATUS_ACTIVE,
                'birthday'                  => '2005-06-15',
                'phone'                     => '09171234567',
                'address_house_lot_unit'    => null,
                'address_street_name'       => null,
                'address_barangay'          => null,
                'address_municipality_city' => 'Sorsogon City',
                'address_province'          => 'Sorsogon',
            ],
            [
                'last_name'                 => 'Santos',
                'first_name'                => 'Maria',
                'middle_initial'            => 'L',
                'email'                     => 'student2@ccdi.edu.ph',
                'student_id'                => '2025-0002',
                'course'                    => 'BS Information Technology',
                'year_level'                => '4th Year',
                'user_status'               => User::STATUS_GRADUATED,
                'birthday'                  => '2002-03-10',
                'phone'                     => '09181234567',
                'address_house_lot_unit'    => null,
                'address_street_name'       => null,
                'address_barangay'          => null,
                'address_municipality_city' => 'Legazpi City',
                'address_province'          => 'Albay',
            ],
            [
                'last_name'                 => 'Ramirez',
                'first_name'                => 'Pedro',
                'middle_initial'            => 'C',
                'email'                     => 'student3@ccdi.edu.ph',
                'student_id'                => '2025-0003',
                'course'                    => 'BS Accountancy',
                'year_level'                => '2nd Year',
                'user_status'               => User::STATUS_DROPPED,
                'birthday'                  => '2004-11-20',
                'phone'                     => '09191234567',
                'address_house_lot_unit'    => null,
                'address_street_name'       => null,
                'address_barangay'          => null,
                'address_municipality_city' => 'Naga City',
                'address_province'          => 'Camarines Sur',
            ],
        ];

        foreach ($students as $s) {
            $user = User::updateOrCreate(
                ['email' => $s['email']],
                [
                    'last_name'                 => $s['last_name'],
                    'first_name'                => $s['first_name'],
                    'middle_initial'            => $s['middle_initial'],
                    'password'                  => Hash::make('password'),
                    'role'                      => 'student',
                    'account_id'                => $s['student_id'],
                    'status'                    => $s['user_status'],
                    'course'                    => $s['course'],
                    'year_level'                => $s['year_level'],
                    'birthday'                  => $s['birthday'],
                    'phone'                     => $s['phone'],
                    'address_house_lot_unit'    => $s['address_house_lot_unit'],
                    'address_street_name'       => $s['address_street_name'],
                    'address_barangay'          => $s['address_barangay'],
                    'address_municipality_city' => $s['address_municipality_city'],
                    'address_province'          => $s['address_province'],
                ]
            );

            // FIX: Set account balance directly — accounts.balance is the single
            // source of truth. total_balance has been removed from students table
            // (migration 2026_03_17_000001_drop_total_balance_from_students_table).
            $user->account()->updateOrCreate([], ['balance' => 8048.00]);

            // FIX: Student record contains only student-specific fields.
            // Dropped columns (last_name, first_name, middle_initial, email, course,
            // year_level, birthday, phone, address, total_balance) were removed in
            // migrations 2026_03_16 and 2026_03_17. Writing them would throw
            // SQLSTATE[42S22] Column not found.
            Student::updateOrCreate(
                ['user_id' => $user->id],
                [
                    'student_id'        => $s['student_id'],
                    'enrollment_status' => 'active',
                ]
            );

            $this->info("✓ Student {$user->name} reset.");
        }
    }
}