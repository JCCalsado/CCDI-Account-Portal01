<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Student;
use App\Models\Account;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules;
use Inertia\Inertia;
use Inertia\Response;

class RegisteredUserController extends Controller
{
    /**
     * Courses that have full subject/assessment data in EnhancedSubjectSeeder.
     *
     * These strings MUST match exactly what EnhancedSubjectSeeder stores in
     * the course column — one character difference = zero subjects found
     * during assessment generation.
     *
     * Removed permanently (no seeder data exists):
     *   - 'Diploma in Electronics and Computer Technology'
     *   - 'Diploma in Software Development and Programming'
     *
     * Removed previously (wrong names — now restored with correct strings):
     *   - 'BET Electronics Engineering Technology' → correct: 'BS Engineering Technology - Electronics'
     *   - 'BET Electrical Engineering Technology'  → correct: 'BS Engineering Technology - Electrical'
     *
     * ACT note: the seeder defines ONE course string 'Associate in Computer Technology'
     * whose curriculum is a Networking specialization. The suffixed variants
     * (- Programming, - Networking, - Multimedia/Animation) never existed in the seeder.
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
     * Show the registration page.
     */
    public function create(): Response
    {
        return Inertia::render('auth/Register', [
            'courses' => $this->allCourses(),
        ]);
    }

    /**
     * Handle an incoming registration request.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'last_name'                => 'required|string|max:255',
            'first_name'               => 'required|string|max:255',
            'middle_initial'           => 'nullable|string|max:10',
            'email'                    => 'required|string|lowercase|email|max:255|unique:' . User::class,
            'password'                 => ['required', 'confirmed', Rules\Password::defaults()],
            'birthday'                 => 'required|date|before:today',
            'year_level'               => 'required|string|max:50',
            'course'                   => ['required', 'string', 'in:' . implode(',', self::COURSES)],
            'address_house_lot_unit'   => 'required|string|max:255',
            'address_street_name'      => 'nullable|string|max:255',
            'address_barangay'         => 'required|string|max:255',
            'address_municipality_city'=> 'required|string|max:255',
            'address_province'         => 'required|string|max:255',
            'phone'                    => 'required|string|max:20',
            'is_irregular'             => 'nullable|boolean',
        ]);

        // Assemble address server-side
        $address = collect([
            $request->address_house_lot_unit,
            $request->address_street_name,
            $request->address_barangay,
            $request->address_municipality_city,
            $request->address_province,
        ])->filter()->implode(', ');

        DB::beginTransaction();
        try {
            $accountId = $this->generateUniqueAccountId();

            $user = User::create([
                'last_name'      => $request->last_name,
                'first_name'     => $request->first_name,
                'middle_initial' => $request->middle_initial,
                'email'          => $request->email,
                'password'       => Hash::make($request->password),
                'birthday'       => $request->birthday,
                'year_level'     => $request->year_level,
                'course'         => $request->course,
                'address'        => $address,
                'phone'          => $request->phone,
                'account_id'     => $accountId,
                'is_irregular'   => (bool) ($request->is_irregular ?? false),
                'status'         => User::STATUS_ACTIVE,
                'role'           => 'student',
            ]);

            Student::create([
                'user_id'           => $user->id,
                'student_id'        => $accountId,
                'enrollment_status' => 'active',
            ]);

            Account::create([
                'user_id' => $user->id,
                'balance' => 0,
            ]);

            DB::commit();

            event(new Registered($user));
            Auth::login($user);

            return to_route('dashboard');

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Generate a unique student account ID in format YYYY-NNNN.
     *
     * NOTE: lockForUpdate() requires an active transaction to hold the lock.
     * This method is called inside the DB::transaction() block in store().
     */
    private function generateUniqueAccountId(): string
    {
        $year = now()->year;

        $lastStudent = User::where('account_id', 'like', "{$year}-%")
            ->lockForUpdate()
            ->orderByRaw('CAST(SUBSTRING(account_id, 6) AS UNSIGNED) DESC')
            ->first();

        if ($lastStudent) {
            $lastNumber = intval(substr($lastStudent->account_id, -4));
            $newNumber  = str_pad($lastNumber + 1, 4, '0', STR_PAD_LEFT);
        } else {
            $newNumber = '0001';
        }

        $newAccountId = "{$year}-{$newNumber}";

        $attempts = 0;
        while (User::where('account_id', $newAccountId)->exists() && $attempts < 10) {
            $lastNumber   = intval($newNumber);
            $newNumber    = str_pad($lastNumber + 1, 4, '0', STR_PAD_LEFT);
            $newAccountId = "{$year}-{$newNumber}";
            $attempts++;
        }

        if ($attempts >= 10) {
            throw new \Exception('Unable to generate unique account ID after multiple attempts.');
        }

        return $newAccountId;
    }

    private function allCourses(): array
    {
        return self::COURSES;
    }
}