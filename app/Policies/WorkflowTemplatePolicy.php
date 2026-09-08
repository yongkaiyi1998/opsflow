<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WorkflowTemplate;

class WorkflowTemplatePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, WorkflowTemplate $workflowTemplate): bool
    {
        return $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, WorkflowTemplate $workflowTemplate): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, WorkflowTemplate $workflowTemplate): bool
    {
        return false;
    }
}
