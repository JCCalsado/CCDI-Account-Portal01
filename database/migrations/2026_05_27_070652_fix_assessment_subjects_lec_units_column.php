<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * FIX #2 — assessment_subjects.lec_units column type
 *
 * The original migration (2026_05_24_052157) defined lec_units as
 * unsignedTinyInteger. NSTP subjects have lec_units = 1.5, which MySQL
 * silently truncates to 1 on insert when the column is an integer type.
 *
 * This migration converts the column to decimal(4,1) so that the 1.5 value
 * is stored and read back correctly.
 *
 * course_unit_preset_subjects.lec_units has the same defect — fixed here too.
 *
 * DATA SAFETY: existing rows with lec_units = 1 for NSTP subjects will remain
 * at 1 after this migration. Run the companion fix artisan command below to
 * correct historical snapshot rows if needed:
 *
 *   UPDATE assessment_subjects
 *   SET lec_units = 1.5
 *   WHERE is_nstp = 1 AND lec_units = 1;
 *
 * The command above is idempotent — safe to run multiple times.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── assessment_subjects.lec_units ─────────────────────────────────────
        Schema::table('assessment_subjects', function (Blueprint $table) {
            // Change from unsignedTinyInteger (integer, truncates 1.5→1)
            // to decimal(4,1) (e.g. 1.5, 17.0, 22.5 — enough headroom for
            // any realistic unit count)
            $table->decimal('lec_units', 4, 1)->default(0)->change();
        });

        // ── course_unit_preset_subjects.lec_units ─────────────────────────────
        // Same defect in the preset subjects table.
        if (Schema::hasTable('course_unit_preset_subjects')) {
            Schema::table('course_unit_preset_subjects', function (Blueprint $table) {
                $table->decimal('lec_units', 4, 1)->default(0)->change();
            });
        }

        // ── Repair existing NSTP rows ─────────────────────────────────────────
        // After the column type change, rows that were stored as 1 (truncated
        // from 1.5) are corrected. is_nstp = true is the authoritative flag.
        // This is safe: any non-NSTP subject with exactly 1.0 lec unit is
        // NOT touched (the WHERE filters by is_nstp = 1 only).
        DB::table('assessment_subjects')
            ->where('is_nstp', true)
            ->where('lec_units', 1.0)
            ->update(['lec_units' => 1.5]);
    }

    public function down(): void
    {
        // Revert to unsignedTinyInteger — WARNING: this will re-truncate any
        // decimal values (1.5 → 1) and lose data. Only roll back in dev.
        Schema::table('assessment_subjects', function (Blueprint $table) {
            $table->unsignedTinyInteger('lec_units')->default(0)->change();
        });

        if (Schema::hasTable('course_unit_preset_subjects')) {
            Schema::table('course_unit_preset_subjects', function (Blueprint $table) {
                $table->unsignedTinyInteger('lec_units')->default(0)->change();
            });
        }
    }
};