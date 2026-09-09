<?php

namespace Database\Factories;

use App\Models\Department;
use App\Models\PurchaseRequest;
use App\Models\SpendCategory;
use App\Models\User;
use App\PurchaseRequestStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PurchaseRequest>
 */
class PurchaseRequestFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'request_no' => fake()->unique()->numerify('PR-2026-######'),
            'requester_id' => User::factory()->for(Department::factory()),
            'department_id' => fn (array $attributes): int => User::query()->findOrFail($attributes['requester_id'])->department_id,
            'vendor_id' => null,
            'category_id' => SpendCategory::factory(),
            'title' => fake()->sentence(4),
            'description' => fake()->paragraph(),
            'currency' => 'MYR',
            'subtotal' => '100.00',
            'tax_amount' => '0.00',
            'total_amount' => '100.00',
            'needed_by_date' => now()->addWeek()->toDateString(),
            'status' => PurchaseRequestStatus::Draft,
            'lock_version' => 1,
        ];
    }
}
