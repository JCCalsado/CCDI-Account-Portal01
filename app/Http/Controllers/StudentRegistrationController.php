<?php

namespace App\Http\Controllers;

use App\Enums\UserRoleEnum;
use App\Models\Account;
use App\Models\Student;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

/**
 * StudentRegistrationController
 *
 * Handles student account creation within the Student Fees module (admin-side quick-create).
 * This is the MINIMAL admin form path — not the full public registration flow.
 *
 * For full student self-registration see:
 *   - RegisteredUserController (submission)
 *   - RegistrationApprovalController (approval → User creation)
 */
class StudentRegistrationController extends Controller
{
    /**
     * Show the Create Student form.
     */
    public function createStudent()
    {
        return Inertia::render('StudentFees/CreateStudent', [
            'courses'    => $this->allCourses(),
            'yearLevels' => ['1st Year', '2nd Year', '3rd Year', '4th Year'],
        ]);
    }

    /**
     * Store a newly created student (admin minimal-form path).
     *
     * Creates three related records in a transaction:
     *   1. User record (auth identity + personal info)
     *   2. Student record (enrollment tracking)
     *   3. Account record (financial tracking)
     *
     * Account ID format: YYYY-NNNN (same as RegistrationApprovalController).
     * Default password: "password" — student must change after first login.
     *
     * BUG FIXES applied here:
     *   - address column removed — was dropped in 2026_05_11 migration; now uses decomposed fields
     *   - STU-XXXXX format replaced with YYYY-NNNN for consistency
     *   - rand() race condition removed — uses generateUniqueAccountId() with lockForUpdate
     */
    public function storeStudent(Request $request)
    {
        $validated = $request->validate([
            'last_name'                 => 'required|string|max:255',
            'first_name'                => 'required|string|max:255',
            'middle_initial'            => 'nullable|string|max:10',
            'email'                     => 'required|email|unique:users,email',
            'birthday'                  => 'required|date',
            'phone'                     => 'required|string|max:20',
            // Decomposed address — mirrors the users table schema after 2026_05_11 migration
            'address_house_lot_unit'    => 'nullable|string|max:255',
            'address_street_name'       => 'nullable|string|max:255',
            'address_barangay'          => 'nullable|string|max:255',
            'address_municipality_city' => 'nullable|string|max:255',
            'address_province'          => 'nullable|string|max:255',
            'year_level'                => 'required|string',
            'course'                    => 'required|string',
            'is_irregular'              => 'nullable|boolean',
        ]);

        DB::beginTransaction();
        try {
            $accountId = $this->generateUniqueAccountId();
            $year      = now()->year;

            // Student ID mirrors the account ID for this path
            $studentId = $accountId;

            $user = User::create([
                'last_name'                 => $validated['last_name'],
                'first_name'                => $validated['first_name'],
                'middle_initial'            => $validated['middle_initial'] ?? null,
                'email'                     => $validated['email'],
                'birthday'                  => $validated['birthday'],
                'phone'                     => $validated['phone'],
                'address_house_lot_unit'    => $validated['address_house_lot_unit'] ?? null,
                'address_street_name'       => $validated['address_street_name'] ?? null,
                'address_barangay'          => $validated['address_barangay'] ?? null,
                'address_municipality_city' => $validated['address_municipality_city'] ?? null,
                'address_province'          => $validated['address_province'] ?? 'Sorsogon',
                'year_level'                => $validated['year_level'],
                'course'                    => $validated['course'],
                'account_id'                => $accountId,
                'role'                      => UserRoleEnum::STUDENT->value,
                'is_irregular'              => $validated['is_irregular'] ?? false,
                'is_active'                 => true,
                'status'                    => User::STATUS_ACTIVE,
                'email_verified_at'         => now(),
                'password'                  => Hash::make('password'),
                'created_by'                => auth()->id(),
            ]);

            Student::create([
                'user_id'           => $user->id,
                'student_id'        => $studentId,
                'enrollment_status' => 'active',
            ]);

            Account::create([
                'user_id'        => $user->id,
                'account_number' => Account::generateAccountNumber(),
                'balance'        => 0,
            ]);

            DB::commit();

            $user->refresh();

            return redirect()
                ->route('student-fees.show', $user->id)
                ->with('success', "Student {$user->first_name} {$user->last_name} (Account ID: {$user->account_id}) created successfully!");

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Student creation failed: ' . $e->getMessage(), [
                'email' => $validated['email'] ?? null,
                'trace' => $e->getTraceAsString(),
            ]);
            return back()->withErrors(['error' => 'Failed to create student: ' . $e->getMessage()]);
        }
    }

    /**
     * Generate a unique YYYY-NNNN account ID.
     *
     * Uses lockForUpdate() to prevent race conditions under concurrent requests.
     * Matches the format used by RegistrationApprovalController::generateUniqueAccountId().
     *
     * NOTE: This duplicates the logic in RegistrationApprovalController.
     * Ideal future state: extract to an AccountIdService or trait.
     */
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
            throw new \RuntimeException('Unable to generate a unique account ID after 20 attempts.');
        }

        return $accountId;
    }

    private function allCourses(): array
    {
        return [
            'Associate in Computer Technology - Networking',
            'BS Computer Science',
            'BS Information Technology',
            'BS Information Systems',
            'BS Engineering Technology - Electronics',
            'BS Engineering Technology - Electrical',
        ];
    }
}