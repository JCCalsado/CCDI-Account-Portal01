<?php

namespace App\Http\Requests\Auth;

use App\Enums\RegistrationStatusEnum;
use App\Models\StudentRegistration;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StudentRegistrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // ── Personal ──────────────────────────────────────────
            'last_name'     => ['required', 'string', 'max:100'],
            'first_name'    => ['required', 'string', 'max:100'],
            'middle_name'   => ['nullable', 'string', 'max:100'],
            'suffix'        => ['nullable', 'string', 'max:20'],
            'gender'        => ['nullable', 'string', Rule::in(['Male', 'Female', 'Other', 'Prefer not to say'])],
            'birthdate'     => ['required', 'date', 'before:today'],
            'civil_status'  => ['nullable', 'string', Rule::in(['Single', 'Married', 'Widowed', 'Separated'])],
            'contact_number'=> ['required', 'string', 'max:20'],
            'email'         => [
                'required', 'email', 'max:255',
                // Block re-registration if a pending/approved/needs_revision record already exists.
                function ($attribute, $value, $fail) {
                    $existing = StudentRegistration::where('email', $value)
                        ->whereIn('status', [
                            RegistrationStatusEnum::PENDING->value,
                            RegistrationStatusEnum::NEEDS_REVISION->value,
                        ])->first();

                    if ($existing) {
                        $fail('A registration with this email is already under review. Track your status using token: ' . $existing->tracking_token);
                    }

                    $approvedRegistration = StudentRegistration::where('email', $value)
                        ->where('status', RegistrationStatusEnum::APPROVED->value)
                        ->first();

                    if ($approvedRegistration) {
                        $fail('An account with this email has already been approved. Please log in instead.');
                    }
                },
            ],

            // ── Address ──────────────────────────────────────────
            'address_house'   => ['nullable', 'string', 'max:255'],
            'address_street'  => ['nullable', 'string', 'max:255'],
            'address_barangay'=> ['required', 'string', 'max:255'],
            'address_city'    => ['required', 'string', 'max:255'],
            'address_province'=> ['required', 'string', 'max:255'],
            'address_zip'     => ['nullable', 'string', 'max:10'],

            // ── Academic ─────────────────────────────────────────
            'existing_student_id' => ['nullable', 'string', 'max:50'],
            'course'         => ['required', 'string', 'max:255'],
            'year_level'     => ['required', 'string', Rule::in(['1st Year', '2nd Year', '3rd Year', '4th Year'])],
            'semester'       => ['required', 'string', Rule::in(['1st Semester', '2nd Semester', 'Summer'])],
            'school_year'    => ['required', 'string', 'max:20', 'regex:/^\d{4}-\d{4}$/'],
            'student_type'   => ['required', 'string', Rule::in(['new', 'old', 'transferee', 'returnee', 'irregular'])],

            // ── Guardian & Emergency ──────────────────────────────
            'guardian_name'    => ['nullable', 'string', 'max:255'],
            'guardian_contact' => ['nullable', 'string', 'max:20'],
            'emergency_contact'=> ['nullable', 'string', 'max:255'],

            // ── Documents ────────────────────────────────────────
            'valid_id'              => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
            'proof_of_enrollment'   => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],

            // ── Account ──────────────────────────────────────────
            'password' => ['required', 'confirmed', Password::defaults()],
        ];
    }

    public function messages(): array
    {
        return [
            'school_year.regex' => 'School year must be in YYYY-YYYY format (e.g. 2024-2025).',
            'email.required'    => 'Email address is required.',
            'birthdate.before'  => 'Birthdate must be in the past.',
        ];
    }
}