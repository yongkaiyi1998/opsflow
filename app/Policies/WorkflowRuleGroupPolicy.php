<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WorkflowRuleGroup;

class WorkflowRuleGroupPolicy
{
    public function view(User $user, WorkflowRuleGroup $workflowRuleGroup): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, WorkflowRuleGroup $workflowRuleGroup): bool
    {
        return $user->isAdmin() && $workflowRuleGroup->version->isDraft();
    }

    public function delete(User $user, WorkflowRuleGroup $workflowRuleGroup): bool
    {
        return $this->update($user, $workflowRuleGroup);
    }
}
