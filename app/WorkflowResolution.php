<?php

namespace App;

use App\Models\WorkflowRuleGroup;
use App\Models\WorkflowStep;
use App\Models\WorkflowVersion;
use Illuminate\Database\Eloquent\Collection;

final readonly class WorkflowResolution
{
    public function __construct(
        public WorkflowVersion $version,
        public WorkflowRuleGroup $ruleGroup,
        public WorkflowContext $context,
    ) {}

    /** @return Collection<int, WorkflowStep> */
    public function steps(): Collection
    {
        return $this->ruleGroup->steps;
    }
}
