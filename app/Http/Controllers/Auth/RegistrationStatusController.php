<?php

namespace App\Http\Controllers\Auth;

use App\Enums\RegistrationStatusEnum;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\StudentRegistrationRequest;
use App\Models\StudentRegistration;
use App\Notifications\NewRegistrationSubmitted;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class RegistrationStatusController extends Controller
{
    /**
     * Show the status tracker for a specific registration token.
     * PUBLIC ROUTE — no auth required.
     */
    public function show(string $token): Response
    {
        $registration = StudentRegistration::where('tracking_token', $token)->firstOrFail();

        return Inertia::render('auth/RegistrationPending', [
            'registration' => $this->serializeForStudent($registration),
        ]);
    }

    /**
     * Show the revision form.
     * Accessed via a signed URL from the revision email.
     */
    public function edit(Request $request, string $token): Response
    {
        $registration = StudentRegistration::where('tracking_token', $token)
            ->where('status', RegistrationStatusEnum::NEEDS_REVISION->value)
            ->firstOrFail();

        return Inertia::render('auth/RegistrationRevise', [
            'registration' => $this->serializeForRevision($registration),
            'token'        => $token,
        ]);
    }

    /**
     * Handle revision resubmission.
     * Validates, updates registration, reverts status to pending.
     */
    public function update(Request $request, string $token): RedirectResponse
    {
        $registration = StudentRegistration::where('tracking_token', $token)
            ->where('status', RegistrationStatusEnum::NEEDS_REVISION->value)
            ->firstOrFail();

        $validated = $request->validate([
            'last_name'          => ['required', 'string', 'max:100'],
            'first_name'         => ['required', 'string', 'max:100'],
            'middle_name'        => ['nullable', 'string', 'max:100'],
            'suffix'             => ['nullable', 'string', 'max:20'],
            'gender'             => ['nullable', 'string'],
            'birthdate'          => ['required', 'date', 'before:today'],
            'civil_status'       => ['nullable', 'string'],
            'contact_number'     => ['required', 'string', 'max:20'],
            'address_house'      => ['nullable', 'string', 'max:255'],
            'address_street'     => ['nullable', 'string', 'max:255'],
            'address_barangay'   => ['required', 'string', 'max:255'],
            'address_city'       => ['required', 'string', 'max:255'],
            'address_province'   => ['required', 'string', 'max:255'],
            'address_zip'        => ['nullable', 'string', 'max:10'],
            'existing_student_id'=> ['nullable', 'string', 'max:50'],
            'course'             => ['required', 'string', 'max:255'],
            'year_level'         => ['required', 'string'],
            'semester'           => ['required', 'string'],
            'school_year'        => ['required', 'string', 'max:20'],
            'student_type'       => ['required', 'string'],
            'guardian_name'      => ['nullable', 'string', 'max:255'],
            'guardian_contact'   => ['nullable', 'string', 'max:20'],
            'emergency_contact'  => ['nullable', 'string', 'max:255'],
            'valid_id'           => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
            'proof_of_enrollment'=> ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
        ]);

        DB::beginTransaction();
        try {
            if ($request->hasFile('valid_id')) {
                if ($registration->valid_id_path) {
                    Storage::disk('private')->delete($registration->valid_id_path);
                }
                $validated['valid_id_path'] = $request->file('valid_id')->store(
                    "registrations/{$registration->id}", 'private'
                );
            }

            if ($request->hasFile('proof_of_enrollment')) {
                if ($registration->proof_of_enrollment_path) {
                    Storage::disk('private')->delete($registration->proof_of_enrollment_path);
                }
                $validated['proof_of_enrollment_path'] = $request->file('proof_of_enrollment')->store(
                    "registrations/{$registration->id}", 'private'
                );
            }

            unset($validated['valid_id'], $validated['proof_of_enrollment']);

            $registration->update(array_merge($validated, [
                'status'         => RegistrationStatusEnum::PENDING->value,
                'revision_notes' => null,
                'submitted_at'   => now(),
            ]));

            DB::commit();

            // Re-notify accounting of the updated submission
            try {
                $accountingUsers = \App\Models\User::where('role', 'accounting')
                    ->where('is_active', true)->get();
                Notification::send($accountingUsers, new NewRegistrationSubmitted($registration));
            } catch (\Exception $e) {
                Log::warning('Failed to re-notify accounting after revision', ['id' => $registration->id]);
            }

            return redirect()->route('registration.status', ['token' => $token])
                ->with('flash.success', 'Your revised registration has been resubmitted successfully.');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Registration revision failed', ['id' => $registration->id, 'error' => $e->getMessage()]);
            return back()->withErrors(['error' => 'Resubmission failed. Please try again.'])->withInput();
        }
    }

    // ── Serializers ───────────────────────────────────────────────────────────

    private function serializeForStudent(StudentRegistration $r): array
    {
        return [
            'id'             => $r->id,
            'tracking_token' => $r->tracking_token,
            'full_name'      => $r->full_name,
            'email'          => $r->email,
            'course'         => $r->course,
            'year_level'     => $r->year_level,
            'student_type'   => $r->student_type,
            'submitted_at'   => $r->submitted_at?->format('F d, Y g:i A'),
            'reviewed_at'    => $r->reviewed_at?->format('F d, Y g:i A'),
            'status'         => $r->status->value,
            'status_label'   => $r->status->label(),
            'status_color'   => $r->status->color(),
            'rejection_reason' => $r->rejection_reason,
            'revision_notes'   => $r->revision_notes,
        ];
    }

    private function serializeForRevision(StudentRegistration $r): array
    {
        return [
            'id'                  => $r->id,
            'tracking_token'      => $r->tracking_token,
            'last_name'           => $r->last_name,
            'first_name'          => $r->first_name,
            'middle_name'         => $r->middle_name,
            'suffix'              => $r->suffix,
            'gender'              => $r->gender,
            'birthdate'           => $r->birthdate?->format('Y-m-d'),
            'civil_status'        => $r->civil_status,
            'contact_number'      => $r->contact_number,
            'email'               => $r->email,
            'address_house'       => $r->address_house,
            'address_street'      => $r->address_street,
            'address_barangay'    => $r->address_barangay,
            'address_city'        => $r->address_city,
            'address_province'    => $r->address_province,
            'address_zip'         => $r->address_zip,
            'existing_student_id' => $r->existing_student_id,
            'course'              => $r->course,
            'year_level'          => $r->year_level,
            'semester'            => $r->semester,
            'school_year'         => $r->school_year,
            'student_type'        => $r->student_type,
            'guardian_name'       => $r->guardian_name,
            'guardian_contact'    => $r->guardian_contact,
            'emergency_contact'   => $r->emergency_contact,
            'revision_notes'      => $r->revision_notes,
            'has_valid_id'        => ! empty($r->valid_id_path),
            'has_proof'           => ! empty($r->proof_of_enrollment_path),
        ];
    }
}