<?php

namespace Database\Factories;

use App\ApprovalMode;
use App\ApprovalStepStatus;
use App\ApproverType;
use App\Models\ApprovalInstance;
use App\Models\ApprovalStepInstance;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ApprovalStepInstance>
 */
class ApprovalStepInstanceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'approval_instance_id' => ApprovalInstance::factory(),
            'workflow_step_id' => null,
            'step_order' => 1,
            'name' => 'Manager approval',
            'approver_type' => ApproverType::RequesterManager,
            'approver_value' => null,
            'approval_mode' => ApprovalMode::Any,
            'required_approvals' => 1,
            'status' => ApprovalStepStatus::Active,
            'started_at' => now(),
            'completed_at' => null,
        ];
    }
}
