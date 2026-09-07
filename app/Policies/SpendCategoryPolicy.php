<?php

namespace App\Policies;

use App\Models\SpendCategory;
use App\Models\User;

class SpendCategoryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, SpendCategory $spendCategory): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, SpendCategory $spendCategory): bool
    {
        return false;
    }
}
