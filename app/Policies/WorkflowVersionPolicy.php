<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WorkflowVersion;
use App\WorkflowVersionStatus;

class WorkflowVersionPolicy
{
    public function view(User $user, WorkflowVersion $workflowVersion): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, WorkflowVersion $workflowVersion): bool
    {
        return $user->isAdmin() && $workflowVersion->isDraft();
    }

    public function delete(User $user, WorkflowVersion $workflowVersion): bool
    {
        return $this->update($user, $workflowVersion);
    }

    public function publish(User $user, WorkflowVersion $workflowVersion): bool
    {
        return $this->update($user, $workflowVersion);
    }

    public function clone(User $user, WorkflowVersion $workflowVersion): bool
    {
        return $user->isAdmin() && $workflowVersion->status === WorkflowVersionStatus::Published;
    }
}
