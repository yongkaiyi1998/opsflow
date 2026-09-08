<?php

namespace Database\Factories;

use App\Models\WorkflowRuleGroup;
use App\Models\WorkflowVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkflowRuleGroup>
 */
class WorkflowRuleGroupFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workflow_version_id' => WorkflowVersion::factory(),
            'name' => fake()->words(2, true),
            'priority' => 10,
            'is_default' => false,
        ];
    }
}
