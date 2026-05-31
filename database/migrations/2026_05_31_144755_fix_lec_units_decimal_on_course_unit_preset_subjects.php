<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fix lec_units and lab_units column types on course_unit_preset_subjects.
 *
 * PROBLEM:
 *   Both columns were created as `unsignedTinyInteger`. MySQL stores only
 *   whole numbers in tinyint. Any insert of 1.5 (NSTP lec_units) is silently
 *   truncated to 1 at the DB layer. The model cast `float` was already correct
 *   (fixed in a prior session) but the DB was eating the fractional part on
 *   write, meaning every NSTP preset subject had lec_units = 1 in the DB.
 *
 * FIX:
 *   Change both to DECIMAL(4,1):
 *     - lec_units: supports 0.0–999.9 (1.5 for NSTP, integer for everything else)
 *     - lab_units: integer values only in practice, but decimal(4,1) is safe
 *       and consistent with lec_units. All existing integer values survive.
 *
 * NOTE: `subjects.lec_units` was already fixed via
 *   `2026_05_25_012652_change_lec_units_to_decimal_on_subjects.php`
 * and `assessment_subjects.lec_units` was fixed via
 *   `2026_05_27_070652_fix_assessment_subjects_lec_units_column.php`
 * This is the final table that needed the same fix.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('course_unit_preset_subjects', function (Blueprint $table) {
            $table->decimal('lec_units', 4, 1)->default(0.0)->change();
            $table->decimal('lab_units', 4, 1)->default(0.0)->change();
        });
    }

    public function down(): void
    {
        Schema::table('course_unit_preset_subjects', function (Blueprint $table) {
            $table->unsignedTinyInteger('lec_units')->default(0)->change();
            $table->unsignedTinyInteger('lab_units')->default(0)->change();
        });
    }
};