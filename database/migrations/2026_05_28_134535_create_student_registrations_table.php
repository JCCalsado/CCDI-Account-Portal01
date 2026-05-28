<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_registrations', function (Blueprint $table) {
            $table->id();

            // ── Tracking ──────────────────────────────────────────────────
            // Opaque token shown to student after submission.
            // Used for status lookup and signed revision URLs.
            $table->string('tracking_token', 64)->unique();

            // ── Personal Information ──────────────────────────────────────
            $table->string('last_name');
            $table->string('first_name');
            $table->string('middle_name')->nullable();
            $table->string('suffix', 20)->nullable();
            $table->string('gender', 20)->nullable();
            $table->date('birthdate');
            $table->string('civil_status', 30)->nullable();
            $table->string('contact_number', 20);
            $table->string('email');

            // ── Address ───────────────────────────────────────────────────
            $table->string('address_house')->nullable();
            $table->string('address_street')->nullable();
            $table->string('address_barangay');
            $table->string('address_city');
            $table->string('address_province');
            $table->string('address_zip', 10)->nullable();

            // ── Academic Information ──────────────────────────────────────
            $table->string('existing_student_id')->nullable(); // Old/returning students only
            $table->string('course');
            $table->string('year_level');
            $table->string('semester');
            $table->string('school_year');
            // new = New Student | old = Old Student | transferee | returnee | irregular
            $table->string('student_type', 30)->default('new');

            // ── Guardian & Emergency ──────────────────────────────────────
            $table->string('guardian_name')->nullable();
            $table->string('guardian_contact', 20)->nullable();
            $table->string('emergency_contact')->nullable();

            // ── Document Uploads ──────────────────────────────────────────
            $table->string('valid_id_path')->nullable();
            $table->string('proof_of_enrollment_path')->nullable();

            // ── Approval Workflow ─────────────────────────────────────────
            // pending | approved | rejected | needs_revision
            $table->string('status', 20)->default('pending')->index();
            $table->text('rejection_reason')->nullable();
            $table->text('revision_notes')->nullable();

            $table->foreignId('reviewed_by')
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete();

            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('submitted_at')->useCurrent();

            // ── Result ────────────────────────────────────────────────────
            // Set only after approval — links the approved registration to its User.
            $table->foreignId('user_id')
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete();

            $table->timestamps();

            // ── Indexes ───────────────────────────────────────────────────
            $table->index('email');
            $table->index('submitted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_registrations');
    }
};