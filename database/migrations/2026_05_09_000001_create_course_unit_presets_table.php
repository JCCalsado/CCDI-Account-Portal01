<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_unit_presets', function (Blueprint $table) {
            $table->id();
            $table->string('course');
            $table->string('year_level');
            $table->string('semester');
            $table->unsignedTinyInteger('lec_units')->default(0);
            $table->unsignedTinyInteger('lab_units')->default(0);
            $table->unsignedTinyInteger('lab_subject_count')->default(0);
            $table->timestamps();

            $table->unique(['course', 'year_level', 'semester']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_unit_presets');
    }
};
