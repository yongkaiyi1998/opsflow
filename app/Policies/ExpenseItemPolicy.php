<?php

namespace App\Policies;

use App\Models\ExpenseItem;
use App\Models\User;

class ExpenseItemPolicy
{
    public function view(User $user, ExpenseItem $expenseItem): bool
    {
        return $user->can('view', $expenseItem->expenseClaim);
    }

    public function addAttachment(User $user, ExpenseItem $expenseItem): bool
    {
        return $user->can('update', $expenseItem->expenseClaim);
    }

    public function deleteAttachment(User $user, ExpenseItem $expenseItem): bool
    {
        return $user->can('update', $expenseItem->expenseClaim);
    }
}
