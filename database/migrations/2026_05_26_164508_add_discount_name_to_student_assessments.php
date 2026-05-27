<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADD discount_name TO student_assessments
 *
 * Stores the specific scholarship or discount label at assessment creation time.
 * Examples: "CHED Full Scholar", "CCDI Institutional", "Academic Excellence Award",
 *           "Faculty/Staff Dependent", "Sibling Discount", etc.
 *
 * This is a nullable free-text field, not an ENUM, because scholarship programs
 * change and should not require schema migrations to extend.
 *
 * Relationship to existing discount columns:
 *   discount_type        ENUM('none','full','nstp','percentage') — the mechanics
 *   discount_percentage  DECIMAL(5,2)                            — the numeric %
 *   discount_name        VARCHAR(150) NULL                       — the human label  ← NEW
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_assessments', function (Blueprint $table) {
            if (! Schema::hasColumn('student_assessments', 'discount_name')) {
                $table->string('discount_name', 150)
                    ->nullable()
                    ->after('discount_percentage')
                    ->comment('Human-readable label for the scholarship or discount applied, e.g. "CHED Full Scholar"');
            }
        });
    }

    public function down(): void
    {
        Schema::table('student_assessments', function (Blueprint $table) {
            if (Schema::hasColumn('student_assessments', 'discount_name')) {
                $table->dropColumn('discount_name');
            }
        });
    }
};