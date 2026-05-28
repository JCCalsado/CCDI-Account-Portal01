<?php

namespace App\Policies;

use App\Enums\UserRoleEnum;
use App\Models\StudentRegistration;
use App\Models\User;

class StudentRegistrationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role === UserRoleEnum::ACCOUNTING || $user->role === UserRoleEnum::ADMIN;
    }

    public function view(User $user, StudentRegistration $registration): bool
    {
        return $user->role === UserRoleEnum::ACCOUNTING || $user->role === UserRoleEnum::ADMIN;
    }

    public function approve(User $user, StudentRegistration $registration): bool
    {
        return ($user->role === UserRoleEnum::ACCOUNTING || $user->role === UserRoleEnum::ADMIN)
            && $registration->isPending();
    }

    public function reject(User $user, StudentRegistration $registration): bool
    {
        return ($user->role === UserRoleEnum::ACCOUNTING || $user->role === UserRoleEnum::ADMIN)
            && ! $registration->isApproved();
    }

    public function requestRevision(User $user, StudentRegistration $registration): bool
    {
        return ($user->role === UserRoleEnum::ACCOUNTING || $user->role === UserRoleEnum::ADMIN)
            && $registration->isPending();
    }
}