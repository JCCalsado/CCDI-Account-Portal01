<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('course_unit_presets', function (Blueprint $table) {
            $table->string('semester')->default('1st Sem')->after('year_level');
            $table->dropUnique(['course', 'year_level']);
            $table->unique(['course', 'year_level', 'semester']);
        });
    }

    public function down(): void
    {
        Schema::table('course_unit_presets', function (Blueprint $table) {
            $table->dropUnique(['course', 'year_level', 'semester']);
            $table->dropColumn('semester');
            $table->unique(['course', 'year_level']);
        });
    }
};
