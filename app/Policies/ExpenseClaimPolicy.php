<?php

namespace App\Policies;

use App\ApprovalAssignmentStatus;
use App\ExpenseClaimStatus;
use App\Models\ExpenseClaim;
use App\Models\User;
use App\UserRole;

class ExpenseClaimPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isActive();
    }

    public function view(User $user, ExpenseClaim $expenseClaim): bool
    {
        return $user->isActive() && (
            $this->hasOperationalVisibility($user)
            || $expenseClaim->employee_id === $user->id
            || $expenseClaim->approvalInstances()
                ->whereHas('steps.assignments', fn ($query) => $query
                    ->where('approver_id', $user->id)
                    ->where('status', ApprovalAssignmentStatus::Pending->value))
                ->exists()
        );
    }

    public function create(User $user): bool
    {
        return $user->isActive() && $user->department_id !== null;
    }

    public function update(User $user, ExpenseClaim $expenseClaim): bool
    {
        return $user->isActive()
            && $expenseClaim->employee_id === $user->id
            && in_array($expenseClaim->status, [ExpenseClaimStatus::Draft, ExpenseClaimStatus::ChangesRequested], true);
    }

    public function delete(User $user, ExpenseClaim $expenseClaim): bool
    {
        return $user->isActive()
            && $expenseClaim->employee_id === $user->id
            && $expenseClaim->status === ExpenseClaimStatus::Draft
            && ! $expenseClaim->approvalInstances()->exists();
    }

    public function submit(User $user, ExpenseClaim $expenseClaim): bool
    {
        return $user->isActive()
            && $expenseClaim->employee_id === $user->id
            && $expenseClaim->status === ExpenseClaimStatus::Draft;
    }

    public function resubmit(User $user, ExpenseClaim $expenseClaim): bool
    {
        return $user->isActive()
            && $expenseClaim->employee_id === $user->id
            && $expenseClaim->status === ExpenseClaimStatus::ChangesRequested;
    }

    public function withdraw(User $user, ExpenseClaim $expenseClaim): bool
    {
        return $user->isActive()
            && $expenseClaim->employee_id === $user->id
            && in_array($expenseClaim->status, [ExpenseClaimStatus::InApproval, ExpenseClaimStatus::ChangesRequested], true);
    }

    public function addAttachment(User $user, ExpenseClaim $expenseClaim): bool
    {
        return $this->update($user, $expenseClaim);
    }

    public function deleteAttachment(User $user, ExpenseClaim $expenseClaim): bool
    {
        return $this->update($user, $expenseClaim);
    }

    private function hasOperationalVisibility(User $user): bool
    {
        return in_array($user->role, [UserRole::Finance, UserRole::Admin], true);
    }
}
