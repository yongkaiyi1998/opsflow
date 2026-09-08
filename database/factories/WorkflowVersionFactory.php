<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\WorkflowTemplate;
use App\Models\WorkflowVersion;
use App\WorkflowVersionStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkflowVersion>
 */
class WorkflowVersionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workflow_template_id' => WorkflowTemplate::factory(),
            'version' => 1,
            'status' => WorkflowVersionStatus::Draft,
            'created_by' => User::factory(),
        ];
    }
}
