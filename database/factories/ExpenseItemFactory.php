<?php

namespace Database\Factories;

use App\Models\ExpenseClaim;
use App\Models\ExpenseItem;
use App\Models\SpendCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExpenseItem>
 */
class ExpenseItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'expense_claim_id' => ExpenseClaim::factory(),
            'category_id' => SpendCategory::factory(),
            'expense_date' => today()->toDateString(),
            'merchant' => fake()->company(),
            'description' => fake()->sentence(),
            'amount' => '100.00',
            'tax_amount' => '0.00',
            'receipt_required' => true,
        ];
    }
}
