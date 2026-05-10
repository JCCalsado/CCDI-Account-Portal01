<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('curriculum_fee_presets', function (Blueprint $table) {
            $table->id();
            $table->string('course');
            $table->string('year_level');
            $table->string('semester');
            $table->unsignedTinyInteger('lec_units')->default(0);
            $table->unsignedTinyInteger('lab_units')->default(0);
            $table->unsignedTinyInteger('lab_subjects')->default(0);
            $table->unsignedTinyInteger('total_units')->default(0);
            $table->boolean('has_nstp')->default(false);
            $table->timestamps();
            $table->unique(['course', 'year_level', 'semester'], 'preset_unique');
        });
    }
    public function down(): void { Schema::dropIfExists('curriculum_fee_presets'); }
};
