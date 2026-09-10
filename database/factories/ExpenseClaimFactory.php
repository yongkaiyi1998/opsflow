<?php

namespace Database\Factories;

use App\ExpenseClaimStatus;
use App\Models\Department;
use App\Models\ExpenseClaim;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExpenseClaim>
 */
class ExpenseClaimFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'claim_no' => fake()->unique()->numerify('EXP-2026-######'),
            'employee_id' => User::factory(),
            'department_id' => Department::factory(),
            'title' => fake()->sentence(4),
            'description' => fake()->paragraph(),
            'currency' => 'MYR',
            'total_amount' => '100.00',
            'status' => ExpenseClaimStatus::Draft,
            'lock_version' => 1,
        ];
    }
}
