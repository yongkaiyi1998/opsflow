<?php

namespace App\Policies;

use App\Models\ApprovalAssignment;
use App\Models\User;

class ApprovalAssignmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isActive();
    }

    public function view(User $user, ApprovalAssignment $approvalAssignment): bool
    {
        return $user->isActive() && $approvalAssignment->approver_id === $user->id;
    }

    public function approve(User $user, ApprovalAssignment $approvalAssignment): bool
    {
        return $this->mayAct($user, $approvalAssignment);
    }

    public function reject(User $user, ApprovalAssignment $approvalAssignment): bool
    {
        return $this->mayAct($user, $approvalAssignment);
    }

    public function requestChanges(User $user, ApprovalAssignment $approvalAssignment): bool
    {
        return $this->mayAct($user, $approvalAssignment);
    }

    private function mayAct(User $user, ApprovalAssignment $approvalAssignment): bool
    {
        if (! $this->view($user, $approvalAssignment)) {
            return false;
        }

        return $approvalAssignment->step->approvalInstance->context()->requesterId !== $user->id;
    }
}
