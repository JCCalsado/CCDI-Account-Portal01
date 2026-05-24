<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * course_unit_preset_subjects
     *
     * Links a CourseUnitPreset to individual subjects from the subjects table.
     * This pivot allows Accounting to see exactly which subjects make up a preset
     * and their per-subject fee contribution.
     *
     * Per-subject fees stored here reflect the rates at the time of assignment —
     * they do NOT auto-update when fee_settings rates change. This is intentional:
     * presets are snapshots of the academic plan, not live billing documents.
     *
     * If rates change, Accounting recalculates via the "Sync Fees" action on the
     * PresetSubjects management page.
     */
    public function up(): void
    {
        Schema::create('course_unit_preset_subjects', function (Blueprint $table) {
            $table->id();

            $table->foreignId('course_unit_preset_id')
                ->constrained('course_unit_presets')
                ->cascadeOnDelete();

            $table->foreignId('subject_id')
                ->constrained('subjects')
                ->cascadeOnDelete();

            // Denormalised from subjects at assignment time — allows presets to
            // show correct unit counts even if the subject row is later edited.
            $table->unsignedTinyInteger('lec_units')->default(0);
            $table->unsignedTinyInteger('lab_units')->default(0);

            // Per-subject fee snapshot (in pesos, 2 decimal places)
            $table->decimal('tuition_fee', 10, 2)->default(0.00)
                ->comment('lec_units × tuition_per_unit rate at assignment time');

            $table->decimal('lab_fee', 10, 2)->default(0.00)
                ->comment('lab_fee_per_subject if lab_units > 0, else 0');

            $table->decimal('total_fee', 10, 2)->default(0.00)
                ->comment('tuition_fee + lab_fee');

            // Classification flags (denormalised for fast UI rendering)
            $table->boolean('is_nstp')->default(false);
            $table->boolean('is_pathfit')->default(false);

            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();

            // One subject can only appear once per preset
            $table->unique(['course_unit_preset_id', 'subject_id'], 'preset_subject_unique');
            $table->index('course_unit_preset_id');
            $table->index('subject_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_unit_preset_subjects');
    }
};