<?php

namespace App\Http\Controllers\Accounting;

use App\Enums\RegistrationStatusEnum;
use App\Enums\UserRoleEnum;
use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\RejectRegistrationRequest;
use App\Http\Requests\Accounting\RequestRevisionRequest;
use App\Models\Account;
use App\Models\Student;
use App\Models\StudentRegistration;
use App\Models\User;
use App\Notifications\RegistrationApproved;
use App\Notifications\RegistrationNeedsRevision;
use App\Notifications\RegistrationRejected;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class RegistrationApprovalController extends Controller
{
    /**
     * List all registrations with filters.
     */
    public function index(Request $request): Response
    {
        $status = $request->get('status', 'pending');
        $search = $request->get('search');

        $registrations = StudentRegistration::query()
            ->when($status !== 'all', fn($q) => $q->where('status', $status))
            ->when($search, function ($q, $s) {
                $q->where(function ($inner) use ($s) {
                    $inner->where('last_name', 'like', "%{$s}%")
                          ->orWhere('first_name', 'like', "%{$s}%")
                          ->orWhere('email', 'like', "%{$s}%")
                          ->orWhere('tracking_token', 'like', "%{$s}%")
                          ->orWhere('contact_number', 'like', "%{$s}%");
                });
            })
            ->with('reviewer:id,first_name,last_name')
            ->orderBy('submitted_at', 'desc')
            ->paginate(20)
            ->withQueryString();

        $counts = [
            'pending'        => StudentRegistration::where('status', 'pending')->count(),
            'needs_revision' => StudentRegistration::where('status', 'needs_revision')->count(),
            'approved'       => StudentRegistration::where('status', 'approved')->count(),
            'rejected'       => StudentRegistration::where('status', 'rejected')->count(),
            'all'            => StudentRegistration::count(),
        ];

        return Inertia::render('Accounting/RegistrationApprovals/Index', [
            'registrations' => $registrations->through(fn($r) => $this->serializeForList($r)),
            'counts'        => $counts,
            'filters'       => ['status' => $status, 'search' => $search],
        ]);
    }

    /**
     * Show the full detail of a registration for review.
     */
    public function show(StudentRegistration $registration): Response
    {
        $registration->load('reviewer:id,first_name,last_name');

        $duplicates   = $registration->detectDuplicates();
        $existingUser = $registration->findMatchingUser();

        return Inertia::render('Accounting/RegistrationApprovals/Show', [
            'registration' => $this->serializeForDetail($registration),
            'duplicates'   => $duplicates->map(fn($d) => [
                'id'             => $d->id,
                'full_name'      => $d->last_name . ', ' . $d->first_name,
                'email'          => $d->email,
                'contact_number' => $d->contact_number,
                'status'         => $d->status->value,
                'submitted_at'   => $d->submitted_at?->format('M d, Y'),
            ]),
            'existingUser' => $existingUser ? [
                'id'        => $existingUser->id,
                'name'      => $existingUser->name,
                'email'     => $existingUser->email,
                'is_active' => $existingUser->is_active,
            ] : null,
            'documentUrls' => [
                'valid_id' => $registration->valid_id_path
                    ? route('accounting.registrations.document', [$registration, 'valid_id'])
                    : null,
                'proof'    => $registration->proof_of_enrollment_path
                    ? route('accounting.registrations.document', [$registration, 'proof'])
                    : null,
            ],
        ]);
    }

    /**
     * Approve a registration.
     *
     * Creates: User → Student → Account.
     * Sends approval email with login instructions.
     *
     * PASSWORD LOGIC:
     * Reads password_hash from the student_registrations row (stored at submission
     * time by RegisteredUserController::store()). If the column is null for any
     * reason (legacy row pre-migration, or manually nulled), a random password is
     * generated and the student must use "Forgot Password" to regain access.
     * The password_hash column is nulled out after the User is created — it has
     * no business living in student_registrations beyond that point.
     */
    public function approve(StudentRegistration $registration): RedirectResponse
    {
        $this->ensureActionable($registration);

        if ($registration->findMatchingUser()) {
            return back()->with('flash.error', 'A user with this email already exists. Cannot approve duplicate.');
        }

        DB::beginTransaction();
        try {
            // ── 1. Generate unique account ID ─────────────────────────
            $accountId = $this->generateUniqueAccountId();

            // ── 2. Resolve password hash ──────────────────────────────
            // Primary source: password_hash column (set at submission time).
            // Fallback: random password — student must use Forgot Password.
            $passwordHash = $registration->password_hash
                ?? Hash::make(str()->random(32));

            $usedFallbackPassword = ! $registration->password_hash;

            // ── 3. Create User record ─────────────────────────────────
            $user = User::create([
                'last_name'                 => $registration->last_name,
                'first_name'                => $registration->first_name,
                'middle_initial'            => $registration->middle_name
                                                ? mb_substr($registration->middle_name, 0, 1)
                                                : null,
                'email'                     => $registration->email,
                'password'                  => $passwordHash,
                'birthday'                  => $registration->birthdate,
                'phone'                     => $registration->contact_number,
                'address_house_lot_unit'    => $registration->address_house,
                'address_street_name'       => $registration->address_street,
                'address_barangay'          => $registration->address_barangay,
                'address_municipality_city' => $registration->address_city,
                'address_province'          => $registration->address_province,
                'course'                    => $registration->course,
                'year_level'                => $registration->year_level,
                'account_id'                => $accountId,
                'is_irregular'              => in_array($registration->student_type, ['irregular'], true),
                'status'                    => User::STATUS_ACTIVE,
                'role'                      => UserRoleEnum::STUDENT->value,
                'is_active'                 => true,
                'email_verified_at'         => now(), // Accounting has verified identity
                'created_by'                => auth()->id(),
            ]);

            // ── 4. Create Student record ──────────────────────────────
            Student::create([
                'user_id'           => $user->id,
                'student_id'        => $accountId,
                'enrollment_status' => 'active',
            ]);

            // ── 5. Create Account record ──────────────────────────────
            Account::create([
                'user_id'        => $user->id,
                'account_number' => Account::generateAccountNumber(),
                'balance'        => 0,
            ]);

            // ── 6. Update registration record ─────────────────────────
            // Null out password_hash — the User record now owns the credential.
            $registration->update([
                'status'        => RegistrationStatusEnum::APPROVED->value,
                'reviewed_by'   => auth()->id(),
                'reviewed_at'   => now(),
                'user_id'       => $user->id,
                'password_hash' => null,
            ]);

            DB::commit();

            // ── 7. Send approval notification ─────────────────────────
            try {
                Notification::route('mail', $registration->email)
                    ->notify(new RegistrationApproved($registration, $user));
            } catch (\Exception $e) {
                Log::warning('Failed to send approval notification', [
                    'registration_id' => $registration->id,
                    'error'           => $e->getMessage(),
                ]);
            }

            // ── 8. Warn if fallback password was used ─────────────────
            // This means the registration pre-dated the password_hash column
            // (legacy row) or the column was unexpectedly null.
            if ($usedFallbackPassword) {
                Log::warning('Approval used fallback random password — student must reset via Forgot Password', [
                    'registration_id' => $registration->id,
                    'user_id'         => $user->id,
                    'email'           => $user->email,
                ]);
            }

            return redirect()
                ->route('accounting.registrations.index')
                ->with('flash.success', "Registration approved. Account {$accountId} created for {$user->first_name} {$user->last_name}.");

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Registration approval failed', [
                'registration_id' => $registration->id,
                'error'           => $e->getMessage(),
                'trace'           => $e->getTraceAsString(),
            ]);

            return back()->with('flash.error', 'Approval failed: ' . $e->getMessage());
        }
    }

    /**
     * Reject a registration.
     */
    public function reject(
        RejectRegistrationRequest $request,
        StudentRegistration $registration
    ): RedirectResponse {
        $this->ensureActionable($registration);

        $registration->update([
            'status'           => RegistrationStatusEnum::REJECTED->value,
            'rejection_reason' => $request->rejection_reason,
            'reviewed_by'      => auth()->id(),
            'reviewed_at'      => now(),
        ]);

        try {
            Notification::route('mail', $registration->email)
                ->notify(new RegistrationRejected($registration));
        } catch (\Exception $e) {
            Log::warning('Failed to send rejection notification', [
                'registration_id' => $registration->id,
                'error'           => $e->getMessage(),
            ]);
        }

        return redirect()
            ->route('accounting.registrations.index')
            ->with('flash.success', 'Registration rejected. The applicant has been notified.');
    }

    /**
     * Request revision from the applicant.
     */
    public function requestRevision(
        RequestRevisionRequest $request,
        StudentRegistration $registration
    ): RedirectResponse {
        $this->ensureActionable($registration);

        $registration->update([
            'status'         => RegistrationStatusEnum::NEEDS_REVISION->value,
            'revision_notes' => $request->revision_notes,
            'reviewed_by'    => auth()->id(),
            'reviewed_at'    => now(),
        ]);

        try {
            Notification::route('mail', $registration->email)
                ->notify(new RegistrationNeedsRevision($registration));
        } catch (\Exception $e) {
            Log::warning('Failed to send revision notification', [
                'registration_id' => $registration->id,
                'error'           => $e->getMessage(),
            ]);
        }

        return redirect()
            ->route('accounting.registrations.index')
            ->with('flash.success', "Revision request sent to {$registration->email}.");
    }

    /**
     * Serve a registration document securely.
     * Only accounting and admin can access.
     */
    public function serveDocument(StudentRegistration $registration, string $type): mixed
    {
        $path = match ($type) {
            'valid_id' => $registration->valid_id_path,
            'proof'    => $registration->proof_of_enrollment_path,
            default    => null,
        };

        if (! $path || ! Storage::disk('private')->exists($path)) {
            abort(404);
        }

        return Storage::disk('private')->response($path);
    }

    // ── Private Helpers ───────────────────────────────────────────────────────

    private function ensureActionable(StudentRegistration $registration): void
    {
        if ($registration->isApproved()) {
            abort(422, 'This registration has already been approved.');
        }
        if ($registration->isRejected()) {
            abort(422, 'This registration has already been rejected. Rejected registrations cannot be re-processed.');
        }
    }

    private function generateUniqueAccountId(): string
    {
        $year = now()->year;

        $last = User::where('account_id', 'like', "{$year}-%")
            ->lockForUpdate()
            ->orderByRaw('CAST(SUBSTRING(account_id, 6) AS UNSIGNED) DESC')
            ->first();

        $lastNumber = $last ? intval(substr($last->account_id, -4)) : 0;
        $newNumber  = str_pad($lastNumber + 1, 4, '0', STR_PAD_LEFT);
        $accountId  = "{$year}-{$newNumber}";

        $attempts = 0;
        while (User::where('account_id', $accountId)->exists() && $attempts < 20) {
            $lastNumber++;
            $newNumber = str_pad($lastNumber + 1, 4, '0', STR_PAD_LEFT);
            $accountId = "{$year}-{$newNumber}";
            $attempts++;
        }

        if ($attempts >= 20) {
            throw new \RuntimeException('Unable to generate a unique account ID.');
        }

        return $accountId;
    }

    // ── Serializers ───────────────────────────────────────────────────────────

    private function serializeForList(StudentRegistration $r): array
    {
        return [
            'id'             => $r->id,
            'tracking_token' => $r->tracking_token,
            'full_name'      => $r->full_name,
            'email'          => $r->email,
            'contact_number' => $r->contact_number,
            'course'         => $r->course,
            'year_level'     => $r->year_level,
            'student_type'   => $r->student_type,
            'status'         => $r->status->value,
            'status_label'   => $r->status->label(),
            'status_color'   => $r->status->color(),
            'submitted_at'   => $r->submitted_at?->format('M d, Y g:i A'),
            'reviewer_name'  => $r->reviewer
                ? $r->reviewer->first_name . ' ' . $r->reviewer->last_name
                : null,
        ];
    }

    private function serializeForDetail(StudentRegistration $r): array
    {
        return [
            'id'                  => $r->id,
            'tracking_token'      => $r->tracking_token,
            'full_name'           => $r->full_name,
            'full_address'        => $r->full_address,
            'last_name'           => $r->last_name,
            'first_name'          => $r->first_name,
            'middle_name'         => $r->middle_name,
            'suffix'              => $r->suffix,
            'gender'              => $r->gender,
            'birthdate'           => $r->birthdate?->format('F d, Y'),
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
            'has_valid_id'        => ! empty($r->valid_id_path),
            'has_proof'           => ! empty($r->proof_of_enrollment_path),
            'status'              => $r->status->value,
            'status_label'        => $r->status->label(),
            'status_color'        => $r->status->color(),
            'rejection_reason'    => $r->rejection_reason,
            'revision_notes'      => $r->revision_notes,
            'submitted_at'        => $r->submitted_at?->format('F d, Y g:i A'),
            'reviewed_at'         => $r->reviewed_at?->format('F d, Y g:i A'),
            'reviewer_name'       => $r->reviewer
                ? $r->reviewer->first_name . ' ' . $r->reviewer->last_name
                : null,
        ];
    }
}