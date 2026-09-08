<?php

namespace Database\Factories;

use App\ApprovalMode;
use App\ApproverType;
use App\Models\WorkflowRuleGroup;
use App\Models\WorkflowStep;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkflowStep>
 */
class WorkflowStepFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workflow_rule_group_id' => WorkflowRuleGroup::factory(),
            'step_order' => 1,
            'name' => 'Manager approval',
            'approver_type' => ApproverType::RequesterManager,
            'approver_value' => null,
            'approval_mode' => ApprovalMode::Any,
            'minimum_approvals' => null,
            'sla_hours' => null,
        ];
    }
}
