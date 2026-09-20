<?php

namespace App\Policies;

use App\Models\IntakeBatch;
use App\Models\User;
use App\UserRole;

class IntakeBatchPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $this->canAccess($user);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, IntakeBatch $intakeBatch): bool
    {
        return $this->canAccess($user);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $this->canAccess($user);
    }

    /**
     * Determine whether the user can update the model.
     */
    private function canAccess(User $user): bool
    {
        return $user->isActive()
            && in_array($user->role, [UserRole::Finance, UserRole::Admin], true);
    }
}
