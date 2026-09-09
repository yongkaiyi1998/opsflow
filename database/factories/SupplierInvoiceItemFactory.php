<?php

namespace Database\Factories;

use App\Models\SupplierInvoice;
use App\Models\SupplierInvoiceItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SupplierInvoiceItem>
 */
class SupplierInvoiceItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'supplier_invoice_id' => SupplierInvoice::factory(),
            'description' => fake()->words(3, true),
            'quantity' => '1.0000',
            'unit_price' => '100.00',
            'subtotal' => '100.00',
        ];
    }
}
