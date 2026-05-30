<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\StudentRegistrationRequest;
use App\Models\StudentRegistration;
use App\Models\User;
use App\Notifications\NewRegistrationSubmitted;
use App\Notifications\RegistrationReceived;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Inertia\Inertia;
use Inertia\Response;

class RegisteredUserController extends Controller
{
    /**
     * Courses that have full subject/assessment data in EnhancedSubjectSeeder.
     * These strings MUST match exactly what the seeder stores.
     */
    private const COURSES = [
        'Associate in Computer Technology - Networking',
        'BS Computer Science',
        'BS Information Technology',
        'BS Information Systems',
        'BS Engineering Technology - Electronics',
        'BS Engineering Technology - Electrical',
    ];

    /**
     * Show the registration form.
     */
    public function create(): Response
    {
        $currentYear = now()->year;

        return Inertia::render('auth/Register', [
            'courses'      => self::COURSES,
            'currentYear'  => $currentYear,
            'schoolYears'  => $this->generateSchoolYears($currentYear),
        ]);
    }

    /**
     * Handle the registration submission.
     *
     * CRITICAL DESIGN NOTE:
     * This does NOT create a User record. It creates a StudentRegistration
     * (pending record) and immediately redirects to the status tracker.
     * No auth session is started. No account is active.
     * The account only becomes active after Accounting approves.
     *
     * PASSWORD STORAGE:
     * The hashed password is stored directly in student_registrations.password_hash.
     * This replaces the previous cache()-based approach which had a 30-day expiry
     * and was silently lost on cache flush. The hash is nulled out after User creation
     * on approval. See: RegistrationApprovalController::approve().
     */
    public function store(StudentRegistrationRequest $request): RedirectResponse
    {
        DB::beginTransaction();
        try {
            $trackingToken = StudentRegistration::generateTrackingToken();
            $passwordHash  = Hash::make($request->password);

            // Create the registration row first so we have the ID for file paths.
            $registration = StudentRegistration::create([
                'tracking_token'     => $trackingToken,
                'last_name'          => $request->last_name,
                'first_name'         => $request->first_name,
                'middle_name'        => $request->middle_name,
                'suffix'             => $request->suffix,
                'gender'             => $request->gender,
                'birthdate'          => $request->birthdate,
                'civil_status'       => $request->civil_status,
                'contact_number'     => $request->contact_number,
                'email'              => $request->email,
                'address_house'      => $request->address_house,
                'address_street'     => $request->address_street,
                'address_barangay'   => $request->address_barangay,
                'address_city'       => $request->address_city,
                'address_province'   => $request->address_province,
                'address_zip'        => $request->address_zip,
                'existing_student_id'=> $request->existing_student_id,
                'course'             => $request->course,
                'year_level'         => $request->year_level,
                'semester'           => $request->semester,
                'school_year'        => $request->school_year,
                'student_type'       => $request->student_type,
                'guardian_name'      => $request->guardian_name,
                'guardian_contact'   => $request->guardian_contact,
                'emergency_contact'  => $request->emergency_contact,
                'password_hash'      => $passwordHash,
                'status'             => 'pending',
                'submitted_at'       => now(),
            ]);

            // ── Store uploaded documents ───────────────────────────────
            $validIdPath           = null;
            $proofOfEnrollmentPath = null;

            if ($request->hasFile('valid_id')) {
                $validIdPath = $request->file('valid_id')->store(
                    "registrations/{$registration->id}",
                    'private'
                );
            }

            if ($request->hasFile('proof_of_enrollment')) {
                $proofOfEnrollmentPath = $request->file('proof_of_enrollment')->store(
                    "registrations/{$registration->id}",
                    'private'
                );
            }

            if ($validIdPath || $proofOfEnrollmentPath) {
                $registration->update([
                    'valid_id_path'            => $validIdPath,
                    'proof_of_enrollment_path' => $proofOfEnrollmentPath,
                ]);
            }

            DB::commit();

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Registration submission failed', [
                'email'   => $request->email,
                'message' => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);

            return back()->withErrors([
                'email' => 'Registration failed due to a system error. Please try again.',
            ])->withInput();
        }

        // ── Post-commit notifications ──────────────────────────────────
        // Both are fire-and-forget — failures are logged but do not affect
        // the student's registration which is already committed to the DB.

        // 1. Acknowledge receipt to the student.
        $this->notifyStudent($registration);

        // 2. Alert accounting staff of the new pending submission.
        $this->notifyAccountingStaff($registration);

        return redirect()
            ->route('registration.status', ['token' => $trackingToken])
            ->with('flash.success', 'Registration submitted! Check your email for a confirmation and track your status using your tracking token.');
    }

    /**
     * Send a receipt acknowledgment to the student.
     * Swallows failures — the registration is already saved.
     */
    private function notifyStudent(StudentRegistration $registration): void
    {
        try {
            Notification::route('mail', $registration->email)
                ->notify(new RegistrationReceived($registration));
        } catch (\Exception $e) {
            Log::warning('Failed to send registration receipt to student', [
                'registration_id' => $registration->id,
                'email'           => $registration->email,
                'error'           => $e->getMessage(),
            ]);
        }
    }

    /**
     * Send new-submission notifications to all active Accounting users.
     * Swallows notification failures — the registration is already saved.
     */
    private function notifyAccountingStaff(StudentRegistration $registration): void
    {
        try {
            $accountingUsers = User::where('role', 'accounting')
                ->where('is_active', true)
                ->get();

            if ($accountingUsers->isNotEmpty()) {
                Notification::send($accountingUsers, new NewRegistrationSubmitted($registration));
            }
        } catch (\Exception $e) {
            Log::warning('Failed to notify accounting staff of new registration', [
                'registration_id' => $registration->id,
                'error'           => $e->getMessage(),
            ]);
        }
    }

    private function generateSchoolYears(int $currentYear): array
    {
        $years = [];
        for ($y = $currentYear - 1; $y <= $currentYear + 1; $y++) {
            $years[] = "{$y}-" . ($y + 1);
        }
        return $years;
    }
}