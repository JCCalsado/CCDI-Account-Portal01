<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * AcademicDataSeeder — Curriculum, Assessment & Demo Payment Data
 *
 * This seeder must be run AFTER the default DatabaseSeeder (migrate:fresh --seed),
 * because it depends on users and workflow templates already existing.
 *
 * Run order is strict — do not reorder:
 *   1. EnhancedSubjectSeeder          — subject master data (curriculum)
 *   2. ComprehensiveAssessmentSeeder  — assessment records per student per term
 *   3. RealisticStudentDataSeeder     — realistic payment simulation
 *   4. WorkflowInstanceSeeder         — demo workflow instances for pending students
 *   5. StudentFirstPaymentSeeder      — first-payment test scenario
 *   6. AdditionalStudentSeeder        — 4 named students with full transaction history
 *
 * Usage:
 *   php artisan db:seed --class=AcademicDataSeeder
 *
 * Full environment reset:
 *   php artisan migrate:fresh --seed && php artisan db:seed --class=AcademicDataSeeder
 *
 * WARNING: Running this seeder twice without a migrate:fresh will create
 * duplicate assessment records. Always reset the database first.
 */
class AcademicDataSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('🎓 Starting academic & demo data seeding...');
        $this->command->info('   (Requires: users, fee settings, and workflow templates to exist)');
        $this->command->newLine();

        $this->guardAgainstMissingPrerequisites();

        // ── Step 1: Subject / Curriculum Master Data ───────────────────────────
        $this->command->info('📚 Step 1: Seeding Subject Curriculum (EnhancedSubjectSeeder)...');
        $this->call(EnhancedSubjectSeeder::class);
        $this->command->newLine();

        // ── Step 2: Student Assessments & Payment Terms ────────────────────────
        // Generates StudentAssessment + StudentPaymentTerm records for all students.
        // Fee formula is driven by config/fees.php — no hardcoded totals.
        $this->command->info('📋 Step 2: Creating Student Assessments & Payment Terms...');
        $this->call(ComprehensiveAssessmentSeeder::class);
        $this->command->newLine();

        // ── Step 3: Realistic Student Enrollments & Payments ───────────────────
        // Simulates historical payment behaviour across cohorts.
        // Depends on Step 2 assessments existing.
        $this->command->info('🎓 Step 3: Seeding Realistic Student Enrollments & Payments...');
        $this->call(RealisticStudentDataSeeder::class);
        $this->command->newLine();

        // ── Step 4: Demo Workflow Instances ────────────────────────────────────
        // Creates sample workflow instances for students with pending enrollment.
        // Gracefully skips if no pending students are found.
        $this->command->info('🔄 Step 4: Creating Sample Workflow Instances...');
        $this->call(WorkflowInstanceSeeder::class);
        $this->command->newLine();

        // ── Step 5: First Payment Test Scenario ────────────────────────────────
        // Creates a controlled first-payment scenario for QA testing.
        // Depends on Step 2 assessments existing.
        $this->command->info('💳 Step 5: Creating First Payment Test Scenario...');
        $this->call(StudentFirstPaymentSeeder::class);
        $this->command->newLine();

        // ── Step 6: Named Test Students with Full Transaction Histories ─────────
        // Adds 4 named students (Maria, Juan, Ana, transaction.history@)
        // each with a complete multi-term payment history for UI/UX testing.
        $this->command->info('🧪 Step 6: Creating 4 Named Test Students with Transaction Histories...');
        $this->call(AdditionalStudentSeeder::class);
        $this->command->newLine();

        $this->command->info('✅ Academic data seeding completed successfully!');
        $this->command->newLine();

        $this->displayAcademicSummary();
    }

    /**
     * Abort early with a clear error if the base system seeders have not been run.
     * Running academic seeders without users or workflow templates will cause
     * foreign-key constraint failures or silent data corruption.
     */
    private function guardAgainstMissingPrerequisites(): void
    {
        $userCount = \App\Models\User::count();

        if ($userCount === 0) {
            $this->command->error('❌ No users found in the database.');
            $this->command->error('   Run the default seeder first:');
            $this->command->error('   php artisan migrate:fresh --seed');
            exit(1);
        }

        $workflowCount = \App\Models\Workflow::count();

        if ($workflowCount === 0) {
            $this->command->error('❌ No workflow templates found in the database.');
            $this->command->error('   Run the default seeder first:');
            $this->command->error('   php artisan migrate:fresh --seed');
            exit(1);
        }

        $this->command->info("   ✓ Prerequisites OK: {$userCount} users, {$workflowCount} workflow templates found.");
        $this->command->newLine();
    }

    private function displayAcademicSummary(): void
    {
        $this->command->info('📊 ACADEMIC SEEDING SUMMARY');
        $this->command->info('═══════════════════════════════════════════════════════');

        $studentCount = \App\Models\User::where('role', 'student')->count();

        $activeStudents    = \App\Models\User::where('role', 'student')->where('status', \App\Models\User::STATUS_ACTIVE)->count();
        $droppedStudents   = \App\Models\User::where('role', 'student')->where('status', \App\Models\User::STATUS_DROPPED)->count();
        $graduatedStudents = \App\Models\User::where('role', 'student')->where('status', \App\Models\User::STATUS_GRADUATED)->count();

        $assessmentCount  = \App\Models\StudentAssessment::count();
        $paymentTermCount = \App\Models\StudentPaymentTerm::count();
        $transactionCount = \App\Models\Transaction::count();

        $workflowInstanceCount = \App\Models\WorkflowInstance::count();
        $activeWorkflows       = \App\Models\WorkflowInstance::whereIn('status', ['pending', 'in_progress'])->count();
        $completedWorkflows    = \App\Models\WorkflowInstance::where('status', 'completed')->count();
        $pendingApprovals      = \App\Models\WorkflowApproval::where('status', 'pending')->count();

        $this->command->table(
            ['Category', 'Count'],
            [
                ['Total Students',               $studentCount],
                ['├─ Active',                    $activeStudents],
                ['├─ Dropped',                   $droppedStudents],
                ['└─ Graduated',                 $graduatedStudents],
                ['',                             ''],
                ['Academic Data',                ''],
                ['├─ Student Assessments',       $assessmentCount],
                ['├─ Payment Terms',             $paymentTermCount],
                ['└─ Transactions',              $transactionCount],
                ['',                             ''],
                ['Workflow Instances',           $workflowInstanceCount],
                ['├─ Active',                    $activeWorkflows],
                ['├─ Completed',                 $completedWorkflows],
                ['└─ Pending Approvals',         $pendingApprovals],
            ]
        );

        $this->command->newLine();
        $this->command->info('🔐 TEST STUDENT CREDENTIALS');
        $this->command->info('═══════════════════════════════════════════════════════');
        $this->command->table(
            ['Role', 'Email', 'Password'],
            [
                ['Student (bulk)',   'student1@ccdi.edu.ph – student100@ccdi.edu.ph', 'password'],
                ['Test: Maria',      'maria.santos@test.com',                         'password'],
                ['Test: Juan',       'juan.dela.cruz@test.com',                       'password'],
                ['Test: Ana',        'ana.garcia@test.com',                           'password'],
                ['Test: TxHistory',  'transaction.history@ccdi.edu.ph',              'password'],
            ]
        );

        $this->command->newLine();
        $this->command->info('💡 NOTES');
        $this->command->info('═══════════════════════════════════════════════════════');
        $this->command->info('• All assessments use the formula from config/fees.php — no hardcoded totals');
        $this->command->info('• lab_units drives the lab fee; entrep fee (₱600) auto-applied when lab_units > 0');
        $this->command->info('• No charge Transactions are seeded — charges come only from admin UI');
        $this->command->info('• transaction.history@ student has 6 accordion sections (5 paid + 1 current)');
        $this->command->newLine();
    }
}