<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add the fields that were collected at registration but silently dropped on approval.
     *
     * Fields added:
     *  - middle_name     : full middle name; middle_initial is now a computed accessor
     *  - suffix          : e.g. Jr., Sr., III
     *  - gender          : Male / Female / Other / Prefer not to say
     *  - civil_status    : Single / Married / Widowed / Separated
     *  - address_zip     : ZIP code — was in student_registrations but had no users column
     *  - guardian_name   : primary guardian full name
     *  - guardian_contact: guardian phone number
     *  - emergency_contact: emergency contact name and/or number
     *
     * NOTE: middle_initial column is intentionally KEPT for now.
     * It is still written by legacy paths (EditStudent, Admin Users Form, StudentController,
     * StudentFeeController). Removing it now would require touching 10+ more files.
     * The User model accessor for middle_initial will prefer middle_name when set,
     * and fall back to the stored middle_initial for records that pre-date this migration.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Personal — after middle_initial so column order is logical
            $table->string('middle_name', 100)->nullable()->after('middle_initial');
            $table->string('suffix', 20)->nullable()->after('middle_name');
            $table->string('gender', 20)->nullable()->after('suffix');
            $table->string('civil_status', 30)->nullable()->after('gender');

            // Address supplement
            $table->string('address_zip', 10)->nullable()->after('address_province');

            // Guardian / Emergency
            $table->string('guardian_name', 255)->nullable()->after('address_zip');
            $table->string('guardian_contact', 20)->nullable()->after('guardian_name');
            $table->string('emergency_contact', 255)->nullable()->after('guardian_contact');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'middle_name',
                'suffix',
                'gender',
                'civil_status',
                'address_zip',
                'guardian_name',
                'guardian_contact',
                'emergency_contact',
            ]);
        });
    }
};