<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Guard against duplicate column — safe to run even if column already exists
        if (Schema::hasColumn('course_unit_presets', 'is_active')) {
            return;
        }

        Schema::table('course_unit_presets', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('lab_subject_count');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('course_unit_presets', 'is_active')) {
            return;
        }

        Schema::table('course_unit_presets', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });
    }
};