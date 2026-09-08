<?php

namespace Database\Factories;

use App\MasterDataStatus;
use App\Models\WorkflowTemplate;
use App\WorkflowModuleType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkflowTemplate>
 */
class WorkflowTemplateFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Purchase Request Approval',
            'code' => fake()->unique()->bothify('WF-#####'),
            'module_type' => WorkflowModuleType::PurchaseRequest,
            'description' => fake()->sentence(),
            'status' => MasterDataStatus::Active,
        ];
    }
}
