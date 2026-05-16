<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds granular audience-filter columns to admin_notifications.
 *
 *  course_filter      — JSON array of course strings (null = all courses)
 *  year_level_filter  — JSON array of year-level strings (null = all year levels)
 *  balance_filter     — enum: 'any' | 'with_balance' | 'overdue'
 *
 * These columns work in conjunction with target_role = 'student'.
 * When role is 'accounting' or 'admin', they are ignored.
 *
 * NULL values mean "no restriction on this dimension" — the scopes in
 * Notification::scopeForCourseYearLevel() treat NULL identically to an
 * empty JSON array (matches all students).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admin_notifications', function (Blueprint $table) {
            // JSON array e.g. ["BS Information Technology", "BS Computer Science"]
            // NULL = no course restriction
            $table->json('course_filter')
                  ->nullable()
                  ->after('user_ids')
                  ->comment('Restrict visibility to students enrolled in these courses. Null = all courses.');

            // JSON array e.g. ["1st Year", "2nd Year"]
            // NULL = no year-level restriction
            $table->json('year_level_filter')
                  ->nullable()
                  ->after('course_filter')
                  ->comment('Restrict visibility to students at these year levels. Null = all year levels.');

            // 'any'          → all students matching role/course/year_level
            // 'with_balance' → only students with balance > 0 on any payment term
            // 'overdue'      → only students with balance > 0 AND due_date < today
            $table->enum('balance_filter', ['any', 'with_balance', 'overdue'])
                  ->default('any')
                  ->after('year_level_filter')
                  ->comment('Further restrict by payment balance state.');
        });
    }

    public function down(): void
    {
        Schema::table('admin_notifications', function (Blueprint $table) {
            $table->dropColumn(['course_filter', 'year_level_filter', 'balance_filter']);
        });
    }
};