<?php

namespace Database\Factories;

use App\ApprovalActionType;
use App\Models\ApprovalAction;
use App\Models\ApprovalInstance;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ApprovalAction>
 */
class ApprovalActionFactory extends Factory
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
            'approval_step_instance_id' => null,
            'actor_id' => User::factory(),
            'action' => ApprovalActionType::Submitted,
            'comment' => null,
            'metadata' => null,
            'created_at' => now(),
        ];
    }
}
