<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_assessments', function (Blueprint $table) {
            $table->decimal('nstp_lec_units', 4, 1)->default(0)->after('lec_units');
            $table->decimal('nstp_tuition', 10, 2)->default(0)->after('nstp_lec_units');
        });
    }

    public function down(): void
    {
        Schema::table('student_assessments', function (Blueprint $table) {
            $table->dropColumn(['nstp_lec_units', 'nstp_tuition']);
        });
    }
};