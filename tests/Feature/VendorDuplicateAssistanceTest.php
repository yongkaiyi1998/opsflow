<?php

namespace Tests\Feature;

use App\DocumentIntakeStatus;
use App\DuplicateMatchClassification;
use App\MasterDataStatus;
use App\Models\Department;
use App\Models\DocumentIntake;
use App\Models\IntakeBatch;
use App\Models\SpendCategory;
use App\Models\SupplierInvoice;
use App\Models\User;
use App\Models\Vendor;
use App\Services\DuplicateDetectionService;
use App\Services\VendorMatchingService;
use App\SupplierInvoiceStatus;
use App\UserRole;
use App\VendorMatchClassification;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class VendorDuplicateAssistanceTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Http::preventStrayRequests();
    }

    public function test_vendor_matching_normalizes_case_spacing_punctuation_and_legal_suffixes(): void
    {
        $vendor = Vendor::factory()->create(['name' => 'ACME Technology Sdn. Bhd.']);
        Vendor::factory()->create(['name' => 'Unrelated Services']);

        $matches = $this->app->make(VendorMatchingService::class)->match('  Acme-Technology SDN BHD ');

        $this->assertCount(1, $matches);
        $this->assertSame($vendor->id, $matches[0]['vendor']->id);
        $this->assertSame(VendorMatchClassification::Likely, $matches[0]['classification']);
        $this->assertSame(['Normalized vendor name matches exactly.'], $matches[0]['reasons']);
    }

    public function test_vendor_matching_returns_stable_ambiguous_candidates_and_ignores_inactive_vendors(): void
    {
        $first = Vendor::factory()->create(['name' => 'North Star Technology']);
        $second = Vendor::factory()->create(['name' => 'Northstar Technologies']);
        Vendor::factory()->create(['name' => 'North Star Tech', 'status' => MasterDataStatus::Inactive]);

        $matches = $this->app->make(VendorMatchingService::class)->match('North Star Technologies');
        $signature = fn (array $items): array => collect($items)->map(fn (array $match): array => [
            $match['vendor']->id,
            $match['classification']->value,
            $match['score'],
            $match['reasons'],
        ])->all();

        $this->assertCount(2, $matches);
        $this->assertEqualsCanonicalizing([$first->id, $second->id], collect($matches)->pluck('vendor.id')->all());
        $this->assertSame($signature($matches), $signature($this->app->make(VendorMatchingService::class)->match('North Star Technologies')));
        $this->assertContains($matches[0]['classification'], [VendorMatchClassification::Likely, VendorMatchClassification::Possible]);
    }

    public function test_vendor_matching_returns_no_match_for_unrelated_or_missing_names(): void
    {
        Vendor::factory()->create(['name' => 'Acme Supplies']);
        $service = $this->app->make(VendorMatchingService::class);

        $this->assertSame([], $service->match('Completely Different Enterprise'));
        $this->assertSame([], $service->match(null));
    }

    public function test_duplicate_detection_classifies_exact_existing_invoice_with_stable_evidence(): void
    {
        $finance = $this->financeUser();
        $vendor = Vendor::factory()->create(['name' => 'ABC Technology Sdn Bhd']);
        $invoice = $this->invoice($finance, $vendor, [
            'invoice_no' => 'INV-2026/0188',
            'invoice_date' => '2026-09-13',
            'total_amount' => '4820.00',
        ], 'Managed network services');
        $intake = $this->intake($finance, [
            'vendor_name' => 'abc technology sdn. bhd.',
            'invoice_no' => 'inv 2026-0188',
            'invoice_date' => '2026-09-13',
            'subtotal' => '4820.00',
            'tax_amount' => '0.00',
            'total_amount' => '4820.00',
            'line_items' => [$this->line('Managed network services', '1.0000', '4820.00')],
        ]);

        $matches = $this->app->make(DuplicateDetectionService::class)->analyze($intake);

        $this->assertSame($invoice->id, $matches[0]['record']->id);
        $this->assertSame(DuplicateMatchClassification::Exact, $matches[0]['classification']);
        $this->assertSame([
            'Vendor matched.',
            'Invoice number matches after normalization.',
            'Total amount matches exactly.',
            'Invoice date matches exactly.',
            'Line-item descriptions are highly similar.',
        ], $matches[0]['reasons']);
    }

    public function test_duplicate_detection_distinguishes_high_medium_and_none_without_floats(): void
    {
        $finance = $this->financeUser();
        $matchedVendor = Vendor::factory()->create(['name' => 'Bright Office Supplies']);
        $otherVendor = Vendor::factory()->create(['name' => 'Different Trading']);
        $high = $this->invoice($finance, $matchedVendor, [
            'invoice_no' => 'BOS-1002',
            'invoice_date' => '2026-09-16',
            'total_amount' => '100.01',
        ], 'Printer supplies');
        $medium = $this->invoice($finance, $otherVendor, [
            'invoice_no' => 'BOS-1003',
            'invoice_date' => '2026-08-01',
            'total_amount' => '100.00',
        ], 'Different goods');
        $this->invoice($finance, $otherVendor, [
            'invoice_no' => 'OTHER-9000',
            'invoice_date' => '2025-01-01',
            'total_amount' => '800.00',
        ], 'Unrelated work');
        $intake = $this->intake($finance, [
            'vendor_name' => 'Bright Office Supplies',
            'invoice_no' => 'BOS-1001',
            'invoice_date' => '2026-09-15',
            'subtotal' => '100.00',
            'tax_amount' => '0.00',
            'total_amount' => '100.00',
            'line_items' => [$this->line('Printer supplies', '1.0000', '100.00')],
        ]);

        $matches = $this->app->make(DuplicateDetectionService::class)->analyze($intake);
        $byId = collect($matches)->where('record_type', 'supplier_invoice')->keyBy(fn (array $match): int => $match['record']->id);

        $this->assertSame(DuplicateMatchClassification::High, $byId[$high->id]['classification']);
        $this->assertContains('Total amount differs by no more than 1%.', $byId[$high->id]['reasons']);
        $this->assertSame(DuplicateMatchClassification::Medium, $byId[$medium->id]['classification']);
        $this->assertCount(2, $byId);
    }

    public function test_same_batch_and_previous_intake_duplicates_are_symmetric_and_stable(): void
    {
        $finance = $this->financeUser();
        $first = $this->intake($finance, $this->candidate('SAME-100'));
        $sameBatch = $this->intakeInBatch($first->intakeBatch, $this->candidate('SAME-100'), 'same-batch.pdf');
        $previous = $this->intake($finance, $this->candidate('SAME-100'));
        $service = $this->app->make(DuplicateDetectionService::class);

        $firstMatches = collect($service->analyze($first))->where('record_type', 'document_intake');
        $reverseMatches = collect($service->analyze($sameBatch))->where('record_type', 'document_intake');

        $this->assertSame(DuplicateMatchClassification::High, $firstMatches->first(fn (array $match): bool => $match['record']->is($sameBatch))['classification']);
        $this->assertSame(DuplicateMatchClassification::High, $reverseMatches->first(fn (array $match): bool => $match['record']->is($first))['classification']);
        $this->assertNotNull($firstMatches->first(fn (array $match): bool => $match['record']->is($previous)));
        $signature = fn (array $matches): array => collect($matches)->map(fn (array $match): array => [
            $match['classification']->value,
            $match['score'],
            $match['reasons'],
            $match['record_type'],
            $match['record']->id,
        ])->all();
        $this->assertSame(
            $signature($service->analyze($first)),
            $signature($service->analyze($first)),
        );
    }

    public function test_verification_page_shows_suggestions_duplicates_links_and_escaped_values(): void
    {
        $finance = $this->financeUser();
        $employee = User::factory()->create();
        $vendor = Vendor::factory()->create(['name' => '<script>alert(1)</script> Acme']);
        $invoice = $this->invoice($finance, $vendor, ['invoice_no' => 'LINK-100']);
        $intake = $this->intake($finance, $this->candidate('LINK-100', $vendor->name));

        $response = $this->actingAs($finance)->get(route('invoice-intakes.documents.show', [$intake->intakeBatch, $intake]));

        $response->assertOk()
            ->assertSee('Vendor suggestions')
            ->assertSee('Possible duplicates')
            ->assertSee(route('supplier-invoices.show', $invoice), false)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false);
        $this->actingAs($employee)
            ->get(route('invoice-intakes.documents.show', [$intake->intakeBatch, $intake]))
            ->assertForbidden();
    }

    public function test_suggested_or_manual_active_vendor_selection_remains_explicit_with_ai_disabled(): void
    {
        config()->set('ai.enabled', false);
        $finance = $this->financeUser();
        $suggested = Vendor::factory()->create(['name' => 'Suggested Vendor']);
        $manual = Vendor::factory()->create(['name' => 'Manual Choice']);
        $department = Department::factory()->create();
        $category = SpendCategory::factory()->create();
        $suggestedIntake = $this->intake($finance, $this->candidate('SUGGESTED-100', $suggested->name));

        $this->actingAs($finance)
            ->post(route('invoice-intakes.documents.verify', [$suggestedIntake->intakeBatch, $suggestedIntake]), $this->verificationPayload($suggested, $department, $category, 'SUGGESTED-100'))
            ->assertRedirect();
        $this->assertSame($suggested->id, SupplierInvoice::query()->sole()->vendor_id);

        $manualIntake = $this->intake($finance, $this->candidate('MANUAL-100', $suggested->name));
        $this->actingAs($finance)
            ->post(route('invoice-intakes.documents.verify', [$manualIntake->intakeBatch, $manualIntake]), $this->verificationPayload($manual, $department, $category, 'MANUAL-100'))
            ->assertRedirect();

        $manualInvoice = SupplierInvoice::query()->where('invoice_no', 'MANUAL-100')->sole();
        $this->assertSame($manual->id, $manualInvoice->vendor_id);
        $this->assertSame(SupplierInvoiceStatus::Draft, $manualInvoice->status);
        $this->assertDatabaseCount('ai_interactions', 0);
    }

    public function test_exact_normalized_duplicate_is_blocked_but_high_similarity_can_proceed(): void
    {
        $finance = $this->financeUser();
        $vendor = Vendor::factory()->create(['name' => 'Duplicate Vendor']);
        $department = Department::factory()->create();
        $category = SpendCategory::factory()->create();
        $this->invoice($finance, $vendor, ['invoice_no' => 'DUP-2026/100', 'total_amount' => '100.00']);
        $exactIntake = $this->intake($finance, $this->candidate('dup 2026-100', $vendor->name));

        $this->actingAs($finance)
            ->post(route('invoice-intakes.documents.verify', [$exactIntake->intakeBatch, $exactIntake]), $this->verificationPayload($vendor, $department, $category, 'dup 2026-100'))
            ->assertSessionHasErrors('invoice_no');
        $this->assertSame(DocumentIntakeStatus::NeedsVerification, $exactIntake->refresh()->status);

        $possibleIntake = $this->intake($finance, $this->candidate('DUP-2026/101', $vendor->name));
        $this->actingAs($finance)
            ->post(route('invoice-intakes.documents.verify', [$possibleIntake->intakeBatch, $possibleIntake]), $this->verificationPayload($vendor, $department, $category, 'DUP-2026/101'))
            ->assertRedirect();

        $this->assertDatabaseCount('supplier_invoices', 2);
        $this->assertSame(DocumentIntakeStatus::Verified, $possibleIntake->refresh()->status);
    }

    public function test_forged_or_inactive_vendor_ids_are_rejected(): void
    {
        $finance = $this->financeUser();
        $inactive = Vendor::factory()->create(['status' => MasterDataStatus::Inactive]);
        $department = Department::factory()->create();
        $category = SpendCategory::factory()->create();

        foreach ([$inactive->id, 999999] as $vendorId) {
            $intake = $this->intake($finance, $this->candidate('FORGED-'.$vendorId));
            $payload = $this->verificationPayload($inactive, $department, $category, 'FORGED-'.$vendorId);
            $payload['vendor_id'] = $vendorId;

            $this->actingAs($finance)
                ->post(route('invoice-intakes.documents.verify', [$intake->intakeBatch, $intake]), $payload)
                ->assertSessionHasErrors('vendor_id');
        }

        $this->assertDatabaseCount('supplier_invoices', 0);
    }

    private function financeUser(): User
    {
        return User::factory()->create(['role' => UserRole::Finance]);
    }

    /** @param array<string, mixed> $overrides */
    private function invoice(User $finance, Vendor $vendor, array $overrides = [], string $itemDescription = 'Standard services'): SupplierInvoice
    {
        $invoice = SupplierInvoice::factory()->create(array_replace([
            'vendor_id' => $vendor->id,
            'submitted_by' => $finance->id,
            'invoice_no' => 'INV-100',
            'invoice_date' => '2026-09-15',
            'subtotal' => '100.00',
            'tax_amount' => '0.00',
            'total_amount' => '100.00',
        ], $overrides));
        $invoice->items()->create([
            'description' => $itemDescription,
            'quantity' => '1.0000',
            'unit_price' => $invoice->subtotal,
            'subtotal' => $invoice->subtotal,
        ]);

        return $invoice;
    }

    /** @param array<string, mixed> $candidate */
    private function intake(User $uploader, array $candidate = []): DocumentIntake
    {
        $batch = IntakeBatch::create(['uploaded_by' => $uploader->id, 'submission_key' => Str::uuid()->toString()]);

        return $this->intakeInBatch($batch, array_replace($this->candidate(), $candidate));
    }

    /** @param array<string, mixed> $candidate */
    private function intakeInBatch(IntakeBatch $batch, array $candidate, string $name = 'invoice.pdf'): DocumentIntake
    {
        $path = 'document-intakes/tests/'.Str::uuid().'.pdf';
        Storage::disk('local')->put($path, "%PDF-1.4\ninvoice\n%%EOF");

        return $batch->documentIntakes()->create([
            'original_name' => $name,
            'disk' => 'local',
            'path' => $path,
            'mime_type' => 'application/pdf',
            'size' => 24,
            'document_type' => 'SUPPLIER_INVOICE',
            'status' => DocumentIntakeStatus::NeedsVerification,
            'extraction_payload' => $candidate,
            'extraction_warnings' => [],
            'extracted_at' => now(),
        ]);
    }

    /** @return array<string, mixed> */
    private function candidate(string $invoiceNumber = 'CANDIDATE-100', string $vendorName = 'Candidate Vendor'): array
    {
        return [
            'vendor_name' => $vendorName,
            'invoice_no' => $invoiceNumber,
            'invoice_date' => '2026-09-15',
            'due_date' => '2026-10-15',
            'currency' => 'MYR',
            'subtotal' => '100.00',
            'tax_amount' => '0.00',
            'total_amount' => '100.00',
            'line_items' => [$this->line('Standard services', '1.0000', '100.00')],
        ];
    }

    /** @return array<string, string> */
    private function line(string $description, string $quantity, string $unitPrice): array
    {
        return [
            'description' => $description,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'subtotal' => $unitPrice,
        ];
    }

    /** @return array<string, mixed> */
    private function verificationPayload(Vendor $vendor, Department $department, SpendCategory $category, string $invoiceNumber): array
    {
        return [
            'vendor_id' => $vendor->id,
            'invoice_no' => $invoiceNumber,
            'department_id' => $department->id,
            'category_id' => $category->id,
            'invoice_date' => '2026-09-15',
            'due_date' => '2026-10-15',
            'description' => 'Verified supplier services.',
            'tax_amount' => '0.00',
            'items' => [['description' => 'Standard services', 'quantity' => '1.0000', 'unit_price' => '100.00']],
        ];
    }
}
