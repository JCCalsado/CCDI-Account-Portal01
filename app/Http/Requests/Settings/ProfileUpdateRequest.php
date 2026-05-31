<?php

namespace App\Http\Requests\Settings;

use App\Enums\UserRoleEnum;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProfileUpdateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        $user = $this->user();

        // Safely resolve the role as a string regardless of Enum cast
        $role = $user->role instanceof UserRoleEnum
            ? $user->role->value
            : (string) $user->role;

        // Base rules for all users
        $rules = [
            // Accept legacy 'name' field but it is not saved directly
            'name'           => ['nullable', 'string', 'max:255'],
            'last_name'      => ['required', 'string', 'max:100'],
            'first_name'     => ['required', 'string', 'max:100'],
            // middle_name replaces middle_initial as the primary editable field.
            // middle_initial is computed from it via accessor.
            'middle_name'    => ['nullable', 'string', 'max:100'],
            'suffix'         => ['nullable', 'string', 'max:20'],
            'gender'         => ['nullable', 'string', Rule::in(['Male', 'Female', 'Other', 'Prefer not to say'])],
            'civil_status'   => ['nullable', 'string', Rule::in(['Single', 'Married', 'Widowed', 'Separated'])],
            'email'          => [
                'required',
                'string',
                'lowercase',
                'email',
                'max:255',
                Rule::unique(User::class)->ignore($user->id),
            ],
            'birthday' => ['nullable', 'date', 'before:today', 'after:1900-01-01'],
            'phone'    => ['nullable', 'string', 'max:20', 'regex:/^([0-9\s\-\+\(\)]*)$/'],
            'address_house_lot_unit'    => ['nullable', 'string', 'max:255'],
            'address_street_name'       => ['nullable', 'string', 'max:255'],
            'address_barangay'          => ['nullable', 'string', 'max:255'],
            'address_municipality_city' => ['nullable', 'string', 'max:255'],
            'address_province'          => ['nullable', 'string', 'max:255'],
            'address_zip'               => ['nullable', 'string', 'max:10'],
            // Guardian / Emergency — students can update these themselves
            'guardian_name'     => ['nullable', 'string', 'max:255'],
            'guardian_contact'  => ['nullable', 'string', 'max:20'],
            'emergency_contact' => ['nullable', 'string', 'max:255'],
        ];

        // Student-specific rules
        if ($role === 'student') {
            $rules['account_id'] = [
                'nullable',
                'string',
                'max:50',
                Rule::unique('users', 'account_id')->ignore($user->id),
            ];

            // Course and Year Level are READ-ONLY for students.
            // They are accepted but stripped in the controller.
            $rules['course']     = ['nullable', 'string', 'max:255'];
            $rules['year_level'] = ['nullable', 'string', 'max:50', 'in:1st Year,2nd Year,3rd Year,4th Year'];

            // Only admins can submit a status change for a student
            $adminRole = $this->user()->role instanceof UserRoleEnum
                ? $this->user()->role->value
                : (string) $this->user()->role;

            if ($adminRole === 'admin') {
                $rules['status'] = ['nullable', Rule::in(['active', 'graduated', 'dropped'])];
            }
        }

        // Faculty field for accounting and admin users
        if (in_array($role, ['accounting', 'admin'], true)) {
            $rules['faculty'] = ['nullable', 'string', 'max:255'];
        }

        return $rules;
    }

    /**
     * Prepare the data for validation.
     * Legacy: if only a `name` field is supplied, split it into first/last name.
     */
    protected function prepareForValidation(): void
    {
        if ($this->filled('name') && ! $this->filled('first_name') && ! $this->filled('last_name')) {
            $parts = explode(' ', trim($this->input('name')), 2);
            $this->merge([
                'first_name' => $parts[0] ?? '',
                'last_name'  => $parts[1] ?? $parts[0] ?? '',
            ]);
        }
    }

    public function attributes(): array
    {
        return [
            'last_name'         => 'last name',
            'first_name'        => 'first name',
            'middle_name'       => 'middle name',
            'suffix'            => 'suffix',
            'gender'            => 'gender',
            'civil_status'      => 'civil status',
            'account_id'        => 'account ID',
            'year_level'        => 'year level',
            'address_zip'       => 'ZIP code',
            'guardian_name'     => 'guardian name',
            'guardian_contact'  => 'guardian contact',
            'emergency_contact' => 'emergency contact',
        ];
    }

    public function messages(): array
    {
        return [
            'phone.regex'       => 'The phone number format is invalid.',
            'birthday.before'   => 'The birthday must be a date before today.',
            'email.unique'      => 'This email address is already in use.',
            'account_id.unique' => 'This account ID is already in use.',
        ];
    }
}