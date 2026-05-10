<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Default seeder — only essential system and application data.
     *
     * Excluded from this run (must be called explicitly):
     *   - EnhancedSubjectSeeder          (subject/curriculum master data)
     *   - ComprehensiveAssessmentSeeder  (student assessment records)
     *   - RealisticStudentDataSeeder     (payment history simulation)
     *   - WorkflowInstanceSeeder         (demo workflow instances)
     *   - StudentFirstPaymentSeeder      (payment test scenario)
     *   - AdditionalStudentSeeder        (named test students with transactions)
     *
     * To seed academic/demo data as well, run:
     *   php artisan db:seed --class=AcademicDataSeeder
     *
     * To seed everything (full demo environment):
     *   php artisan db:seed --class=AcademicDataSeeder  (after this seeder)
     *
     * Standalone curriculum seeders (not wired into any group seeder):
     *   php artisan db:seed --class=CurriculumSubjectsSeeder
     *   php artisan db:seed --class=CourseUnitPresetsSeeder
     *   php artisan db:seed --class=CurriculumFeePresetSeeder
     *   php artisan db:seed --class=QuickStudentAssessmentSeeder
     */
    public function run(): void
    {
        $this->command->info('🚀 Starting essential system seeding...');
        $this->command->newLine();

        // ── Step 1: Users ──────────────────────────────────────────────────────
        // Seeds: 1 admin, 1 accounting, 100 students.
        $this->command->info('👥 Step 1: Seeding Users (Admin, Accounting, 100 Students)...');
        $this->call(ComprehensiveUserSeeder::class);
        $this->command->newLine();

        // ── Step 2: Fee Settings ───────────────────────────────────────────────
        // Syncs config/fees.php values into the fee_settings table.
        $this->command->info('💰 Step 2: Seeding Fee Settings (from config/fees.php)...');
        $this->call(FeeSettingsSeeder::class);
        $this->command->newLine();

        // ── Step 3: Workflow Templates ─────────────────────────────────────────
        // Seeds workflow definitions required for the approval pipeline.
        // These are structural, not academic — they must always exist.
        $this->command->info('⚙️  Step 3: Seeding Workflow Templates...');
        $this->call(DemoWorkflowSeeder::class);
        $this->call(PaymentApprovalWorkflowSeeder::class);
        $this->command->newLine();

        // ── Step 4: Announcements ──────────────────────────────────────────────
        // Seeds sample announcement notifications visible on the student dashboard.
        $this->command->info('🔔 Step 4: Seeding Notifications...');
        $this->call(NotificationSeeder::class);
        $this->command->newLine();

        $this->command->info('✅ Essential system seeding completed.');
        $this->command->newLine();

        $this->displaySummary();

        $this->displayNextSteps();
    }

    private function displaySummary(): void
    {
        $this->command->info('📊 SEEDING SUMMARY');
        $this->command->info('═══════════════════════════════════════════════════════');

        $userCount       = \App\Models\User::count();
        $adminCount      = \App\Models\User::where('role', 'admin')->count();
        $accountingCount = \App\Models\User::where('role', 'accounting')->count();
        $studentCount    = \App\Models\User::where('role', 'student')->count();

        $workflowCount = \App\Models\Workflow::count();

        $tuitionRate = (float) config('fees.tuition_per_lec_unit', 364.00);
        $labRate     = (float) config('fees.lab.per_unit', 1656.00);
        $entrepFee   = (float) config('fees.lab.entrepreneurship_fee', 600.00);
        $miscFee     = (float) config('fees.misc_fee_fixed', 4700.00);

        $this->command->table(
            ['Category', 'Count'],
            [
                ['Total Users',          $userCount],
                ['├─ Admins',            $adminCount],
                ['├─ Accounting Staff',  $accountingCount],
                ['└─ Students',          $studentCount],
                ['',                     ''],
                ['Workflow Templates',   $workflowCount],
            ]
        );

        $this->command->newLine();
        $this->command->info('💡 FEE FORMULA (config/fees.php)');
        $this->command->info('═══════════════════════════════════════════════════════');
        $this->command->info("  Tuition:  lec_units × ₱{$tuitionRate} per unit");
        $this->command->info("  Lab Fee:  (lab_units × ₱{$labRate}) + ₱{$entrepFee} entrep fee");
        $this->command->info("  Misc Fee: ₱{$miscFee} (fixed per semester)");

        $this->command->newLine();
        $this->command->info('🔐 DEFAULT CREDENTIALS');
        $this->command->info('═══════════════════════════════════════════════════════');
        $this->command->table(
            ['Role', 'Email', 'Password'],
            [
                ['Admin',      'admin@ccdi.edu.ph',                             'password'],
                ['Accounting', 'accounting@ccdi.edu.ph',                        'password'],
                ['Students',   'student1@ccdi.edu.ph – student100@ccdi.edu.ph', 'password'],
            ]
        );
        $this->command->newLine();
    }

    private function displayNextSteps(): void
    {
        $this->command->info('⚡ NEXT STEPS');
        $this->command->info('═══════════════════════════════════════════════════════');
        $this->command->info('  No assessment data has been seeded yet.');
        $this->command->info('  Students exist but have no assessments or payment terms.');
        $this->command->newLine();
        $this->command->info('  To seed academic/demo data (subjects → assessments → payments):');
        $this->command->info('    php artisan db:seed --class=AcademicDataSeeder');
        $this->command->newLine();
        $this->command->info('  To seed only specific academic seeders individually:');
        $this->command->info('    php artisan db:seed --class=EnhancedSubjectSeeder');
        $this->command->info('    php artisan db:seed --class=ComprehensiveAssessmentSeeder');
        $this->command->info('    php artisan db:seed --class=RealisticStudentDataSeeder');
        $this->command->info('    php artisan db:seed --class=WorkflowInstanceSeeder');
        $this->command->info('    php artisan db:seed --class=StudentFirstPaymentSeeder');
        $this->command->info('    php artisan db:seed --class=AdditionalStudentSeeder');
        $this->command->newLine();
        $this->command->info('  Standalone curriculum seeders (run order matters):');
        $this->command->info('    php artisan db:seed --class=CurriculumSubjectsSeeder');
        $this->command->info('    php artisan db:seed --class=CourseUnitPresetsSeeder');
        $this->command->info('    php artisan db:seed --class=CurriculumFeePresetSeeder');
        $this->command->info('    php artisan db:seed --class=QuickStudentAssessmentSeeder');
        $this->command->newLine();
        $this->command->info('  Full reset with everything:');
        $this->command->info('    php artisan migrate:fresh --seed && php artisan db:seed --class=AcademicDataSeeder');
        $this->command->newLine();
    }
}