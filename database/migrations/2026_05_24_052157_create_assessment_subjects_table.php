<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * assessment_subjects
     *
     * Immutable billing snapshot: records exactly which subjects were used
     * to compute a student assessment, with per-subject fees locked at
     * the rates that were active when the assessment was created.
     *
     * WHY THIS EXISTS:
     *   - The subjects table and fee_settings rates can change over time.
     *   - A student's SOA must always show the subjects and fees that were
     *     in effect when their assessment was generated — not current values.
     *   - This table is written once during StudentFeeController::store()
     *     and is never updated (delete + re-insert on update() only).
     *
     * RELATIONSHIP TO student_assessments:
     *   student_assessments.id = assessment_subjects.student_assessment_id
     *   student_assessments stores the AGGREGATE (lec_units, lab_units, tuition_fee, etc.)
     *   assessment_subjects stores the LINE-ITEM snapshot used to produce that aggregate.
     *
     * IRREGULAR STUDENTS:
     *   Irregular students have no curriculum subjects — assessment_subjects will
     *   have zero rows for their assessments. This is correct and expected.
     */
    public function up(): void
    {
        Schema::create('assessment_subjects', function (Blueprint $table) {
            $table->id();

            // FK to student_assessments — uses student_assessment_id to match
            // the convention established by student_payment_terms
            $table->foreignId('student_assessment_id')
                ->constrained('student_assessments')
                ->cascadeOnDelete();

            // FK to subjects — nullable because the subject might be deleted
            // after the assessment was created. The snapshot fields below
            // preserve the data regardless.
            $table->foreignId('subject_id')
                ->nullable()
                ->constrained('subjects')
                ->nullOnDelete();

            // Snapshot of subject identity at assessment creation time
            $table->string('code', 50);
            $table->string('name', 200);

            // Snapshot of unit counts at assessment creation time
            $table->unsignedTinyInteger('lec_units')->default(0);
            $table->unsignedTinyInteger('lab_units')->default(0);

            // Classification (denormalised for fast PDF/display rendering)
            $table->boolean('is_nstp')->default(false);
            $table->boolean('is_pathfit')->default(false);
            $table->boolean('is_billable')->default(true)
                ->comment('false for NSTP and PATHFIT');

            // Per-subject fee snapshot — rates locked at assessment creation time
            $table->decimal('tuition_fee', 10, 2)->default(0.00)
                ->comment('lec_units × rate (0 for NSTP if billed separately, 0 for PATHFIT)');

            $table->decimal('lab_fee', 10, 2)->default(0.00)
                ->comment('lab_fee_per_subject if lab_units > 0, else 0. Excludes entrepreneurship_fee (charged once at assessment level).');

            $table->decimal('total_fee', 10, 2)->default(0.00)
                ->comment('tuition_fee + lab_fee for this subject row');

            // NSTP is billed at 1.5 units fixed — stored separately so the snapshot
            // correctly shows the NSTP tuition contribution even when is_nstp = true.
            $table->decimal('nstp_billing_units', 4, 1)->default(0.0)
                ->comment('1.5 when is_nstp = true, 0 otherwise');

            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();

            $table->index('student_assessment_id');
            $table->index('subject_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assessment_subjects');
    }
};