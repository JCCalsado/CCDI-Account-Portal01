<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add password_hash to student_registrations.
     *
     * Replaces the cache()-based password storage introduced in
     * RegisteredUserController::store(). The cache approach had a 30-day
     * expiry and would silently lose the student's chosen password if
     * approval was delayed or the cache was flushed during a deploy.
     *
     * This column stores the bcrypt hash — never the plaintext password.
     * It is nulled out after the User record is created on approval.
     */
    public function up(): void
    {
        Schema::table('student_registrations', function (Blueprint $table) {
            $table->string('password_hash')->nullable()->after('proof_of_enrollment_path');
        });
    }

    public function down(): void
    {
        Schema::table('student_registrations', function (Blueprint $table) {
            $table->dropColumn('password_hash');
        });
    }
};