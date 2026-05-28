<?php

namespace App\Http\Middleware;

use App\Enums\UserRoleEnum;
use App\Models\StudentAssessment;
use App\Models\StudentRegistration;
use App\Models\User;
use Illuminate\Foundation\Inspiring;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    public function share(Request $request): array
    {
        [$message, $author] = str(Inspiring::quotes()->random())->explode('-');

        return [
            ...parent::share($request),
            'name'                       => config('app.name'),
            'unreadNotificationsCount'   => $this->resolveUnreadNotificationsCount($request),
            'pendingRegistrationsCount'  => $this->resolvePendingRegistrationsCount($request),
            'quote'                      => ['message' => trim($message), 'author' => trim($author)],
            'auth'                       => ['user' => $this->resolveAuthUser($request)],
            'latestAssessmentInfo'       => $this->resolveLatestAssessmentInfo($request),
            'sidebarOpen'                => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'csrf_token'                 => csrf_token(),
            'flash'                      => [
                'error'   => $request->session()->pull('flash.error'),
                'warning' => $request->session()->pull('flash.warning'),
                'success' => $request->session()->pull('flash.success'),
                'info'    => $request->session()->pull('flash.info'),
            ],
        ];
    }

    private function resolveUnreadNotificationsCount(Request $request): int
    {
        $user = $request->user();
        if (! $user) return 0;
        if ($user->isAdmin()) return 0;

        $cacheKey = "unread_notifications_count:{$user->id}";
        return Cache::remember($cacheKey, now()->addMinutes(2), function () use ($user) {
            return \App\Models\Notification::active()
                ->forUser($user->id)
                ->withinDateRange()
                ->unread()
                ->count();
        });
    }

    /**
     * Count pending registrations — shown as badge for Accounting/Admin.
     * Cached for 5 minutes to avoid hitting the DB on every request.
     */
    private function resolvePendingRegistrationsCount(Request $request): int
    {
        $user = $request->user();
        if (! $user) return 0;

        $role = $user->role instanceof UserRoleEnum ? $user->role : null;
        if (! in_array($role, [UserRoleEnum::ACCOUNTING, UserRoleEnum::ADMIN], true)) {
            return 0;
        }

        return Cache::remember('pending_registrations_count', now()->addMinutes(5), function () {
            return StudentRegistration::where('status', 'pending')->count();
        });
    }

    private function resolveAuthUser(Request $request): ?array
    {
        $user = $request->user();
        if (! $user) return null;

        $role = $user->role instanceof UserRoleEnum
            ? $user->role->value
            : (string) $user->role;

        $avatar = $user->profile_picture
            ? asset('storage/' . $user->profile_picture)
            : null;

        return [
            'id'              => $user->id,
            'name'            => $user->name,
            'first_name'      => $user->first_name,
            'last_name'       => $user->last_name,
            'middle_initial'  => $user->middle_initial,
            'email'           => $user->email,
            'role'            => $role,
            'avatar'          => $avatar,
            'profile_picture' => $user->profile_picture,
            'account_id'      => $user->account_id,
            'course'          => $user->course,
            'year_level'      => $user->year_level,
            'is_irregular'    => $user->is_irregular,
            'birthday'        => $user->birthday?->format('Y-m-d'),
            'phone'           => $user->phone,
            'address_house_lot_unit'    => $user->address_house_lot_unit,
            'address_street_name'       => $user->address_street_name,
            'address_barangay'          => $user->address_barangay,
            'address_municipality_city' => $user->address_municipality_city,
            'address_province'          => $user->address_province,
            'faculty'         => $user->faculty,
            'status'          => $user->status,
            'department'      => $user->department,
            'is_active'       => $user->is_active,
            'email_verified_at' => $user->email_verified_at,
        ];
    }

    private function resolveLatestAssessmentInfo(Request $request): ?array
    {
        $user = $request->user();
        if (! $user || $user->role !== UserRoleEnum::STUDENT) return null;

        $cacheKey = "student_assessment_info:{$user->id}";
        return Cache::remember($cacheKey, now()->addMinutes(5), function () use ($user) {
            $assessment = StudentAssessment::where('user_id', $user->id)
                ->where('status', 'active')
                ->latest()
                ->first(['year_level', 'semester', 'school_year']);
            if (! $assessment) return null;
            return [
                'year_level'  => $assessment->year_level,
                'semester'    => $assessment->semester,
                'school_year' => $assessment->school_year,
            ];
        });
    }
}