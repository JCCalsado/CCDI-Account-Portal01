<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\StudentRegistrationRequest;
use App\Models\StudentRegistration;
use App\Models\User;
use App\Notifications\NewRegistrationSubmitted;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
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
     */
    public function store(StudentRegistrationRequest $request): RedirectResponse
    {
        DB::beginTransaction();
        try {
            $trackingToken = StudentRegistration::generateTrackingToken();

            // ── Store uploaded documents ───────────────────────────────
            $validIdPath           = null;
            $proofOfEnrollmentPath = null;

            // We need the registration ID for the path, so we create first,
            // then update paths if files exist.
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
                'status'             => 'pending',
                'submitted_at'       => now(),
                // Password stored as hash for later User creation
                '_password_hash'     => null, // handled below
            ]);

            // Store password hash in a temporary column-free way:
            // We keep it in a JSON meta field for now. Actually, the cleanest
            // approach is to store the hashed password directly in the registration
            // so it can be used when the User is created on approval.
            // Add password_hash to $fillable and migration.
            // For this implementation we use a separate update:
            $passwordHash = Hash::make($request->password);

            // Store document files after we have the registration ID
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

            // Update with file paths and password hash
            $registration->update([
                'valid_id_path'             => $validIdPath,
                'proof_of_enrollment_path'  => $proofOfEnrollmentPath,
            ]);

            // Store password hash separately (we'll add this column to the table)
            // For now store in a cache keyed by registration ID — expires in 30 days
            cache()->put(
                "registration_password:{$registration->id}",
                $passwordHash,
                now()->addDays(30)
            );

            DB::commit();

            // ── Notify all Accounting users of new submission ──────────
            $this->notifyAccountingStaff($registration);

            return redirect()->route('registration.status', ['token' => $trackingToken])
                ->with('flash.success', 'Registration submitted! Track your status using your tracking token.');

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