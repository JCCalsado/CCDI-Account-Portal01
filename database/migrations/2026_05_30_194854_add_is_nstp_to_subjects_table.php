<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Add is_nstp flag to subjects table.
 *
 * PURPOSE:
 *   Replaces the fragile string-sniff detection (str_contains($code, 'NSTP'))
 *   in AssessmentService with a reliable, explicit database flag.
 *
 * WHY is_nstp AND NOT subject_type ENUM:
 *   Only NSTP subjects require special billing treatment (excluded from the
 *   100% discount waiver). PATHFIT is billed identically to regular subjects
 *   at CCDI. A single boolean is the minimum required — no enum needed.
 *
 * ROLE-BASED EDITING:
 *   is_nstp is an academic/curriculum classification. Only Admin and Super Admin
 *   roles may modify this flag via SubjectController. Accounting cannot.
 *
 * SEEDER NOTE:
 *   EnhancedSubjectSeeder will be updated to auto-populate this column:
 *     'is_nstp' => str_contains(strtoupper($code), 'NSTP')
 *   This correctly identifies all 12 NSTP subjects across 6 programs.
 *
 * BACK-FILL:
 *   This migration back-fills existing rows using the same code-based detection
 *   used by the legacy AssessmentService::isNstpSubject(). This is safe because
 *   all NSTP subject codes in ccdi_portal contain 'NSTP' (case-insensitive):
 *     ACT-NSTP1, ACT-NSTP2, CS-NSTP1, CS-NSTP2, ECE-NSTP1, ECE-NSTP2,
 *     EET-NSTP1, EET-NSTP2, IS-NSTP1, IS-NSTP2, IT-NSTP1, IT-NSTP2
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subjects', function (Blueprint $table) {
            $table->boolean('is_nstp')
                ->default(false)
                ->after('lab_units')
                ->comment('True for National Service Training Program subjects. Billing: lec_units × rate always charged; excluded from 100% discount waiver.');
        });

        // Back-fill existing rows — detect by code containing 'NSTP' (case-insensitive).
        // This is a one-time data repair; going forward AssessmentService reads is_nstp directly.
        DB::table('subjects')
            ->whereRaw('UPPER(code) LIKE ?', ['%NSTP%'])
            ->update(['is_nstp' => true]);
    }

    public function down(): void
    {
        Schema::table('subjects', function (Blueprint $table) {
            $table->dropColumn('is_nstp');
        });
    }
};