<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('course_unit_presets', 'has_nstp')) {
            return;
        }

        Schema::table('course_unit_presets', function (Blueprint $table) {
            // Whether this course/year/semester includes an NSTP subject.
            // When true, billing adds 1.5 fixed NSTP units (₱546) on top of
            // billable lec units. NSTP is never discounted at 100%.
            $table->boolean('has_nstp')->default(false)->after('lab_subject_count');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('course_unit_presets', 'has_nstp')) {
            return;
        }

        Schema::table('course_unit_presets', function (Blueprint $table) {
            $table->dropColumn('has_nstp');
        });
    }
};