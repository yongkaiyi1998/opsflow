<?php

namespace Database\Factories;

use App\ApprovalAssignmentStatus;
use App\Models\ApprovalAssignment;
use App\Models\ApprovalStepInstance;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ApprovalAssignment>
 */
class ApprovalAssignmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'approval_step_instance_id' => ApprovalStepInstance::factory(),
            'approver_id' => User::factory(),
            'status' => ApprovalAssignmentStatus::Pending,
            'assigned_at' => now(),
            'acted_at' => null,
            'delegated_from_user_id' => null,
        ];
    }
}
