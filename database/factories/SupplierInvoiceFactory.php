<?php

namespace Database\Factories;

use App\Models\Department;
use App\Models\SpendCategory;
use App\Models\SupplierInvoice;
use App\Models\User;
use App\Models\Vendor;
use App\SupplierInvoiceStatus;
use App\UserRole;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SupplierInvoice>
 */
class SupplierInvoiceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'internal_no' => fake()->unique()->numerify('INV-2026-######'),
            'invoice_no' => fake()->unique()->bothify('SUP-########'),
            'vendor_id' => Vendor::factory(),
            'department_id' => Department::factory(),
            'category_id' => SpendCategory::factory(),
            'submitted_by' => User::factory()->state(['role' => UserRole::Finance]),
            'invoice_date' => now()->subDay()->toDateString(),
            'due_date' => now()->addMonth()->toDateString(),
            'currency' => 'MYR',
            'subtotal' => '100.00',
            'tax_amount' => '0.00',
            'total_amount' => '100.00',
            'description' => fake()->sentence(),
            'status' => SupplierInvoiceStatus::Draft,
            'lock_version' => 1,
        ];
    }
}
