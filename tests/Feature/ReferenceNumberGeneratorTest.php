<?php

namespace Tests\Feature;

use App\ReferenceType;
use App\Services\ReferenceNumberGenerator;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use OverflowException;
use Tests\TestCase;

class ReferenceNumberGeneratorTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_generates_supported_reference_formats_with_independent_sequences(): void
    {
        $generator = app(ReferenceNumberGenerator::class);

        $this->assertSame('PR-2026-000001', $generator->next(ReferenceType::PurchaseRequest, 2026));
        $this->assertSame('PR-2026-000002', $generator->next(ReferenceType::PurchaseRequest, 2026));
        $this->assertSame('INV-2026-000001', $generator->next(ReferenceType::SupplierInvoice, 2026));
        $this->assertSame('EXP-2026-000001', $generator->next(ReferenceType::ExpenseClaim, 2026));
        $this->assertSame('PR-2027-000001', $generator->next(ReferenceType::PurchaseRequest, 2027));

        $this->assertDatabaseCount('reference_sequences', 4);
    }

    public function test_issued_numbers_are_not_reused_when_business_records_are_absent_or_deleted(): void
    {
        $generator = app(ReferenceNumberGenerator::class);

        $this->assertSame('PR-2026-000001', $generator->next(ReferenceType::PurchaseRequest, 2026));
        $this->assertSame('PR-2026-000002', $generator->next(ReferenceType::PurchaseRequest, 2026));
        $this->assertDatabaseHas('reference_sequences', ['type' => 'PR', 'year' => 2026, 'last_number' => 2]);
    }

    public function test_database_prevents_duplicate_sequence_rows(): void
    {
        $values = ['type' => 'PR', 'year' => 2026, 'last_number' => 1, 'created_at' => now(), 'updated_at' => now()];
        DB::table('reference_sequences')->insert($values);

        $this->expectException(QueryException::class);
        DB::table('reference_sequences')->insert($values);
    }

    public function test_exhausted_sequences_fail_without_advancing_the_counter(): void
    {
        DB::table('reference_sequences')->insert([
            'type' => 'PR', 'year' => 2026, 'last_number' => 999999, 'created_at' => now(), 'updated_at' => now(),
        ]);

        try {
            app(ReferenceNumberGenerator::class)->next(ReferenceType::PurchaseRequest, 2026);
            $this->fail('Expected an exhausted sequence to fail.');
        } catch (OverflowException) {
            $this->assertDatabaseHas('reference_sequences', ['type' => 'PR', 'year' => 2026, 'last_number' => 999999]);
        }
    }
}
