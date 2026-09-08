<?php

namespace Database\Factories;

use App\Models\WorkflowRule;
use App\Models\WorkflowRuleGroup;
use App\WorkflowRuleField;
use App\WorkflowRuleOperator;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkflowRule>
 */
class WorkflowRuleFactory extends Factory
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
            'field' => WorkflowRuleField::Amount,
            'operator' => WorkflowRuleOperator::GreaterThan,
            'value' => '1000.00',
        ];
    }
}
