<?php

namespace Database\Factories;

use App\ApprovalInstanceStatus;
use App\Models\ApprovalInstance;
use App\Models\Department;
use App\Models\WorkflowRuleGroup;
use App\WorkflowModuleType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ApprovalInstance>
 */
class ApprovalInstanceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'approvable_type' => Department::class,
            'approvable_id' => Department::factory(),
            'workflow_rule_group_id' => WorkflowRuleGroup::factory(),
            'workflow_version_id' => fn (array $attributes): int => WorkflowRuleGroup::query()
                ->findOrFail($attributes['workflow_rule_group_id'])
                ->workflow_version_id,
            'status' => ApprovalInstanceStatus::InProgress,
            'current_step_order' => 1,
            'workflow_context' => [
                'module_type' => WorkflowModuleType::PurchaseRequest->value,
                'requester_id' => 1,
                'department_id' => null,
                'category_id' => null,
                'amount' => '100.00',
                'currency' => 'MYR',
            ],
            'started_at' => now(),
            'completed_at' => null,
        ];
    }
}
