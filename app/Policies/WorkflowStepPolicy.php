<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WorkflowStep;

class WorkflowStepPolicy
{
    public function update(User $user, WorkflowStep $workflowStep): bool
    {
        return $user->isAdmin() && $workflowStep->ruleGroup->version->isDraft();
    }

    public function delete(User $user, WorkflowStep $workflowStep): bool
    {
        return $this->update($user, $workflowStep);
    }
}
