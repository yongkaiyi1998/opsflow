<?php

namespace Database\Factories;

use App\MasterDataStatus;
use App\Models\SpendCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SpendCategory>
 */
class SpendCategoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'code' => fake()->unique()->bothify('CAT-###'),
            'status' => MasterDataStatus::Active,
        ];
    }
}
