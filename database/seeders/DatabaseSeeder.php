<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Default seeder — essential system and application data only.
     *
     * Excluded from this run (must be called explicitly via AcademicDataSeeder):
     *   - EnhancedSubjectSeeder          (subject/curriculum master data)
     *   - ComprehensiveAssessmentSeeder  (student assessment records)
     *   - RealisticStudentDataSeeder     (payment history simulation)
     *   - WorkflowInstanceSeeder         (demo workflow instances)
     *   - StudentFirstPaymentSeeder      (payment test scenario)
     *   - AdditionalStudentSeeder        (named test students with transactions)
     *
     * Standalone curriculum seeders (not part of this group):
     *   php artisan db:seed --class=CurriculumSubjectsSeeder
     *   php artisan db:seed --class=CourseUnitPresetSubjectsSeeder
     *   php artisan db:seed --class=CurriculumFeePresetSeeder
     *   php artisan db:seed --class=QuickStudentAssessmentSeeder
     *
     * To seed academic/demo data as well, run after this:
     *   php artisan db:seed --class=AcademicDataSeeder
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

        // ── Step 2: Admin Permissions ──────────────────────────────────────────
        // Seeds admin_permissions, admin_role_permissions.
        // Must run before any code that resolves hasPermission() checks.
        $this->command->info('🔐 Step 2: Seeding Admin Permissions...');
        $this->call(AdminPermissionSeeder::class);
        $this->command->newLine();

        // ── Step 3: Fee Settings ───────────────────────────────────────────────
        // Syncs fee_settings table with canonical school year rates.
        $this->command->info('💰 Step 3: Seeding Fee Settings...');
        $this->call(FeeSettingsSeeder::class);
        $this->command->newLine();

        // ── Step 4: Course Unit Presets ────────────────────────────────────────
        // Seeds course_unit_presets — required for preset-based assessment generation.
        // Must run after FeeSettingsSeeder (no hard FK, but logically dependent).
        $this->command->info('📚 Step 4: Seeding Course Unit Presets...');
        $this->call(CourseUnitPresetsSeeder::class);
        $this->command->newLine();

        // ── Step 5: Workflow Templates ─────────────────────────────────────────
        // Seeds workflow definitions required for the approval pipeline.
        $this->command->info('⚙️  Step 5: Seeding Workflow Templates...');
        $this->call(DemoWorkflowSeeder::class);
        $this->call(PaymentApprovalWorkflowSeeder::class);
        $this->command->newLine();

        // ── Step 6: Announcements ──────────────────────────────────────────────
        // Seeds sample announcement notifications for the student dashboard.
        $this->command->info('🔔 Step 6: Seeding Notifications...');
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
        $permCount     = \DB::table('admin_permissions')->count();
        $presetCount   = \DB::table('course_unit_presets')->where('is_active', true)->count();

        // Read misc total from DB — the canonical source of truth.
        // config/fees.php is for billing logic only; its misc_fee_fixed is display-only.
        $miscFee = (float) \DB::table('fee_settings')
            ->whereIn('category', ['miscellaneous', 'other'])
            ->sum('amount');

        $tuitionRate = (float) \DB::table('fee_settings')
            ->where('key', 'tuition_per_unit')
            ->value('amount');

        $labRate = (float) \DB::table('fee_settings')
            ->where('key', 'lab_fee_per_subject')
            ->value('amount');

        $entrepFee = (float) \DB::table('fee_settings')
            ->where('key', 'entrepreneurship_fee')
            ->value('amount');

        $this->command->table(
            ['Category', 'Count / Value'],
            [
                ['Total Users',           $userCount],
                ['├─ Admins',             $adminCount],
                ['├─ Accounting Staff',   $accountingCount],
                ['└─ Students',           $studentCount],
                ['',                      ''],
                ['Workflow Templates',    $workflowCount],
                ['Admin Permissions',     $permCount],
                ['Active Course Presets', $presetCount],
            ]
        );

        $this->command->newLine();
        $this->command->info('💡 FEE FORMULA (from fee_settings table)');
        $this->command->info('═══════════════════════════════════════════════════════');
        $this->command->info("  Tuition:  lec_units × ₱{$tuitionRate} per unit");
        $this->command->info("  Lab Fee:  (lab_subjects × ₱{$labRate}) + ₱{$entrepFee} entrep fee");
        $this->command->info("  Misc Fee: ₱" . number_format($miscFee, 2) . " (fixed per semester)");

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
        $this->command->info('  To seed specific academic seeders individually:');
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
        $this->command->info('  Full reset:');
        $this->command->info('    php artisan migrate:fresh --seed && php artisan db:seed --class=AcademicDataSeeder');
        $this->command->newLine();
    }
}