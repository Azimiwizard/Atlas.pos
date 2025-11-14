<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;

abstract class BasePolicy
{
    protected function isAdmin(User $user): bool
    {
        return $user->role === UserRole::ADMIN;
    }

    protected function isManager(User $user): bool
    {
        return $user->role === UserRole::MANAGER;
    }

    protected function isCashier(User $user): bool
    {
        return $user->role === UserRole::CASHIER;
    }
}

