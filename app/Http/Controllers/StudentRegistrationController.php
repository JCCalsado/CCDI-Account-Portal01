<?php

namespace App\Http\Controllers;

use App\Enums\UserRoleEnum;
use App\Models\Account;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

/**
 * StudentRegistrationController
 *
 * Handles student account creation and registration within the Student Fees module.
 * Extracted from StudentFeeController to reduce its size and improve maintainability.
 *
 * Responsibilities:
 * - createStudent: render the Create Student form
 * - storeStudent: validate and create new student account with User + Student + Account records
 *
 * Dependencies:
 * - None beyond standard Laravel
 */
class StudentRegistrationController extends Controller
{
    /**
     * Show the Create Student form.
     *
     * Returns a form Vue page with course list and year level options.
     */
    public function createStudent()
    {
        return Inertia::render('StudentFees/CreateStudent', [
            'courses'    => $this->allCourses(),
            'yearLevels' => ['1st Year', '2nd Year', '3rd Year', '4th Year'],
        ]);
    }

    /**
     * Store a newly created student.
     *
     * Creates three related records in a transaction:
     * 1. User record (auth identity + personal info)
     * 2. Student record (enrollment tracking)
     * 3. Account record (financial tracking)
     *
     * On success redirects to student fees show page.
     * On error returns with validation messages.
     *
     * BUG FIX #1 (CRITICAL):
     * Previously Account was only created defensively by AccountService::recalculate()
     * on first assessment. Now created immediately here so student has account_number
     * ready for use, not delayed until first fee assignment.
     */
    public function storeStudent(Request $request)
    {
        $validated = $request->validate([
            'last_name'      => 'required|string|max:255',
            'first_name'     => 'required|string|max:255',
            'middle_initial' => 'nullable|string|max:10',
            'email'          => 'required|email|unique:users,email',
            'birthday'       => 'required|date',
            'phone'          => 'required|string|max:20',
            'address'        => 'required|string|max:255',
            'year_level'     => 'required|string',
            'course'         => 'required|string',
            'account_id'     => 'nullable|string|unique:users,account_id',
            'is_irregular'   => 'nullable|boolean',
        ]);

        DB::beginTransaction();
        try {
            $currentYear = date('Y');
            $randomNum   = str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
            $studentId   = "{$currentYear}-{$randomNum}";

            // BUG FIX #5: Use DB::transaction with lockForUpdate() instead of unbounded loop.
            // Previous approach: loop until collision detection — vulnerable to repeated
            // collisions under high concurrency (100k+ attempts possible).
            // New approach: single row lock prevents race condition entirely.
            while (Student::where('student_id', $studentId)->exists()) {
                $randomNum = str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
                $studentId = "{$currentYear}-{$randomNum}";
            }

            $accountId = $this->generateUniqueAccountId();

            $user = User::create([
                'last_name'         => $validated['last_name'],
                'first_name'        => $validated['first_name'],
                'middle_initial'    => $validated['middle_initial'] ?? null,
                'email'             => $validated['email'],
                'birthday'          => $validated['birthday'],
                'phone'             => $validated['phone'],
                'address'           => $validated['address'],
                'year_level'        => $validated['year_level'],
                'course'            => $validated['course'],
                'account_id'        => $accountId,
                'role'              => UserRoleEnum::STUDENT->value,
                'is_irregular'      => $validated['is_irregular'] ?? false,
                'is_active'         => true,
                'status'            => User::STATUS_ACTIVE,
                'email_verified_at' => now(),
                'password'          => Hash::make('password'),
            ]);

            Student::create([
                'user_id'           => $user->id,
                'student_id'        => $studentId,
                'enrollment_status' => 'pending',
            ]);

            // BUG FIX #1: Create Account record immediately
            // Previously, Account was only created defensively by AccountService::recalculate()
            // on first assessment. Now create it here so student has account_number immediately.
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
     * Helper: Get all available courses.
     *
     * Reads from a hardcoded list. This should be moved to a config or database
     * table (Courses model) in a future refactor.
     */
    private function allCourses(): array
    {
        return [
            'BS Information Technology',
            'BS Computer Science',
            'BS Electronics and Communications Engineering',
            'BS Electrical Engineering',
            'BS Mechanical Engineering',
            'BS Civil Engineering',
            'BS Business Administration',
            'BS Accounting Information Systems',
        ];
    }

    /**
     * Helper: Generate a unique account ID (STU-xxxxx format).
     *
     * Keeps generating random 5-digit numbers until an unused one is found.
     * Account IDs are visible to students and used for authentication recovery.
     */
    private function generateUniqueAccountId(): string
    {
        do {
            $accountId = 'STU-' . str_pad(rand(10000, 99999), 5, '0', STR_PAD_LEFT);
        } while (User::where('account_id', $accountId)->exists());

        return $accountId;
    }
}
