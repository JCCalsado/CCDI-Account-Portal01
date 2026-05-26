<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADD: discount_name to student_assessments
 *
 * Stores the human-readable scholarship or discount label
 * (e.g. "CHED Full Scholar", "CCDI Institutional Grant", "Academic Excellence").
 *
 * Nullable — existing assessments have no named discount and will show null.
 * The application layer renders null as "No discount" or "—" in the UI.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_assessments', function (Blueprint $table) {
            if (! Schema::hasColumn('student_assessments', 'discount_name')) {
                $table->string('discount_name', 150)
                    ->nullable()
                    ->default(null)
                    ->after('discount_percentage')
                    ->comment('Human-readable scholarship or discount label. Null = no discount applied.');
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