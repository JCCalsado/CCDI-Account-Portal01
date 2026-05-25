<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Change subjects.lec_units from integer to decimal(4,1).
 *
 * Reason: NSTP subjects carry 1.5 lecture units per CHED curriculum.
 * The previous integer column silently truncated 1.5 → 1 on insert.
 *
 * decimal(4,1) supports values up to 999.9, which is sufficient for
 * any realistic lecture unit count. Existing integer values (e.g. 3)
 * are stored as 3.0 with no loss of precision.
 *
 * AssessmentService::NSTP_MINIMUM_UNITS = 1.5 remains the billing source
 * of truth. This column now accurately reflects the curriculum value.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subjects', function (Blueprint $table) {
            $table->decimal('lec_units', 4, 1)->default(0)->change();
        });
    }

    public function down(): void
    {
        Schema::table('subjects', function (Blueprint $table) {
            // Reverting to integer will truncate any fractional values.
            // Ensure no 1.5 values exist before rolling back.
            $table->integer('lec_units')->default(0)->change();
        });
    }
};