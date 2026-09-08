<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WorkflowRule;

class WorkflowRulePolicy
{
    public function update(User $user, WorkflowRule $workflowRule): bool
    {
        return $user->isAdmin() && $workflowRule->ruleGroup->version->isDraft();
    }

    public function delete(User $user, WorkflowRule $workflowRule): bool
    {
        return $this->update($user, $workflowRule);
    }
}
