<?php

namespace Tests\Feature;

use App\DocumentIntakeStatus;
use App\MasterDataStatus;
use App\Models\Attachment;
use App\Models\Department;
use App\Models\DocumentIntake;
use App\Models\IntakeBatch;
use App\Models\SpendCategory;
use App\Models\SupplierInvoice;
use App\Models\User;
use App\Models\Vendor;
use App\Services\DocumentIntakeVerificationService;
use App\SupplierInvoiceStatus;
use App\UserRole;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

class DocumentIntakeVerificationTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_finance_and_admin_can_open_the_verification_form(): void
    {
        $finance = User::factory()->create(['role' => UserRole::Finance]);
        $admin = User::factory()->admin()->create();
        $intake = $this->intake($finance);

        foreach ([$finance, $admin] as $actor) {
            $this->actingAs($actor)
                ->get(route('invoice-intakes.documents.show', [$intake->intakeBatch, $intake]))
                ->assertOk()
                ->assertSee('Verify and create draft')
                ->assertSee('AI extracted candidate');
        }
    }

    public function test_finance_verifies_corrected_values_into_one_authoritative_draft(): void
    {
        [$finance, $vendor, $department, $category] = $this->businessData();
        $intake = $this->intake($finance, [
            'invoice_no' => 'AI-WRONG',
            'subtotal' => '9999.99',
            'tax_amount' => '50.00',
            'total_amount' => '10049.99',
        ]);
        $payload = $this->payload($vendor, $department, $category, [
            'invoice_no' => ' CORRECTED-100 ',
            'tax_amount' => '0.13',
            'subtotal' => '0.01',
            'total_amount' => '0.02',
        ]);

        $response = $this->actingAs($finance)->post(
            route('invoice-intakes.documents.verify', [$intake->intakeBatch, $intake]),
            $payload,
        );

        $invoice = SupplierInvoice::query()->sole();
        $response->assertRedirect(route('invoice-intakes.documents.show', [$intake->intakeBatch, $intake]));
        $this->assertSame('CORRECTED-100', $invoice->invoice_no);
        $this->assertSame('12.37', $invoice->subtotal);
        $this->assertSame('0.13', $invoice->tax_amount);
        $this->assertSame('12.50', $invoice->total_amount);
        $this->assertSame(SupplierInvoiceStatus::Draft, $invoice->status);
        $this->assertNull($invoice->submitted_at);
        $this->assertDatabaseCount('approval_instances', 0);

        $intake->refresh();
        $this->assertSame(DocumentIntakeStatus::Verified, $intake->status);
        $this->assertSame($invoice->id, $intake->supplier_invoice_id);
        $this->assertSame($finance->id, $intake->verified_by);
        $this->assertNotNull($intake->verified_at);
        $this->assertDatabaseCount('attachments', 1);
        $this->assertSame($intake->path, $invoice->attachments()->sole()->path);
        $this->assertDatabaseHas('activity_logs', [
            'subject_type' => SupplierInvoice::class,
            'subject_id' => $invoice->id,
            'action' => 'SUPPLIER_INVOICE_CREATED',
        ]);
        $this->assertDatabaseHas('activity_logs', [
            'subject_type' => DocumentIntake::class,
            'subject_id' => $intake->id,
            'action' => 'DOCUMENT_INTAKE_STATUS_CHANGED',
        ]);
    }

    public function test_admin_can_verify_but_employee_cannot_view_or_verify(): void
    {
        [$finance, $vendor, $department, $category] = $this->businessData();
        $admin = User::factory()->admin()->create();
        $employee = User::factory()->create();
        $intake = $this->intake($finance);
        $route = route('invoice-intakes.documents.verify', [$intake->intakeBatch, $intake]);

        $this->actingAs($employee)->get(route('invoice-intakes.documents.show', [$intake->intakeBatch, $intake]))->assertForbidden();
        $this->actingAs($employee)->post($route, $this->payload($vendor, $department, $category))->assertForbidden();
        $this->assertDatabaseCount('supplier_invoices', 0);

        $this->actingAs($admin)->post($route, $this->payload($vendor, $department, $category))->assertRedirect();
        $this->assertSame($admin->id, $intake->refresh()->verified_by);
    }

    public function test_invalid_or_inactive_business_values_are_rejected_without_changing_the_intake(): void
    {
        [$finance, $vendor, $department, $category] = $this->businessData();
        $vendor->update(['status' => MasterDataStatus::Inactive]);
        $intake = $this->intake($finance);
        $payload = $this->payload($vendor, $department, $category, [
            'items' => [['description' => 'Invalid', 'quantity' => '-1', 'unit_price' => '5.00']],
        ]);

        $this->actingAs($finance)
            ->post(route('invoice-intakes.documents.verify', [$intake->intakeBatch, $intake]), $payload)
            ->assertSessionHasErrors(['vendor_id', 'items.0.quantity']);

        $this->assertDatabaseCount('supplier_invoices', 0);
        $this->assertSame(DocumentIntakeStatus::NeedsVerification, $intake->refresh()->status);
        Storage::disk('local')->assertExists($intake->path);
    }

    public function test_only_needs_verification_status_can_create_a_draft(): void
    {
        [$finance, $vendor, $department, $category] = $this->businessData();

        foreach ([DocumentIntakeStatus::Pending, DocumentIntakeStatus::Processing, DocumentIntakeStatus::Failed, DocumentIntakeStatus::Skipped, DocumentIntakeStatus::Verified] as $status) {
            $intake = $this->intake($finance, status: $status);
            $this->actingAs($finance)
                ->post(route('invoice-intakes.documents.verify', [$intake->intakeBatch, $intake]), $this->payload($vendor, $department, $category, [
                    'invoice_no' => 'STATUS-'.$status->value,
                ]))
                ->assertSessionHasErrors('verification');
        }

        $this->assertDatabaseCount('supplier_invoices', 0);
    }

    public function test_repeated_verification_does_not_create_a_second_invoice(): void
    {
        [$finance, $vendor, $department, $category] = $this->businessData();
        $intake = $this->intake($finance);
        $payload = $this->payload($vendor, $department, $category);
        $service = $this->app->make(DocumentIntakeVerificationService::class);
        $first = $service->verify($intake, $payload, $finance);

        try {
            $service->verify($intake, $payload, $finance);
            $this->fail('A verified intake must not create another draft.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('verification', $exception->errors());
        }

        $this->assertDatabaseCount('supplier_invoices', 1);
        $this->assertDatabaseCount('attachments', 1);
        $this->assertSame($first->id, $intake->refresh()->supplier_invoice_id);
    }

    public function test_duplicate_supplier_invoice_constraint_is_reused_by_verification(): void
    {
        [$finance, $vendor, $department, $category] = $this->businessData();
        SupplierInvoice::factory()->create(['vendor_id' => $vendor, 'invoice_no' => 'DUP-100']);
        $intake = $this->intake($finance);

        $this->actingAs($finance)
            ->post(route('invoice-intakes.documents.verify', [$intake->intakeBatch, $intake]), $this->payload($vendor, $department, $category, [
                'invoice_no' => ' DUP-100 ',
            ]))
            ->assertSessionHasErrors('invoice_no');

        $this->assertDatabaseCount('supplier_invoices', 1);
        $this->assertSame(DocumentIntakeStatus::NeedsVerification, $intake->refresh()->status);
    }

    public function test_attachment_failure_rolls_back_invoice_and_preserves_original(): void
    {
        [$finance, $vendor, $department, $category] = $this->businessData();
        $intake = $this->intake($finance);
        Event::listen('eloquent.creating: '.Attachment::class, function (): never {
            throw new RuntimeException('Simulated metadata failure.');
        });

        try {
            $this->app->make(DocumentIntakeVerificationService::class)
                ->verify($intake, $this->payload($vendor, $department, $category), $finance);
            $this->fail('The simulated attachment failure should abort verification.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated metadata failure.', $exception->getMessage());
        }

        $this->assertDatabaseCount('supplier_invoices', 0);
        $this->assertDatabaseCount('attachments', 0);
        $this->assertSame(DocumentIntakeStatus::NeedsVerification, $intake->refresh()->status);
        Storage::disk('local')->assertExists($intake->path);
    }

    public function test_verified_source_remains_private_and_cannot_be_deleted_from_the_draft(): void
    {
        [$finance, $vendor, $department, $category] = $this->businessData();
        $employee = User::factory()->create();
        $intake = $this->intake($finance);
        $invoice = $this->app->make(DocumentIntakeVerificationService::class)
            ->verify($intake, $this->payload($vendor, $department, $category), $finance);
        $attachment = $invoice->attachments()->sole();

        $this->actingAs($finance)->get(route('invoice-intakes.documents.original', [$intake->intakeBatch, $intake]))->assertOk();
        $this->actingAs($finance)->get(route('attachments.download', $attachment))->assertOk();
        $this->actingAs($employee)->get(route('attachments.download', $attachment))->assertForbidden();
        $this->actingAs($finance)->delete(route('attachments.destroy', $attachment))->assertForbidden();
        $this->actingAs($finance)->delete(route('supplier-invoices.destroy', $invoice))->assertForbidden();
        Storage::disk('local')->assertExists($intake->path);
    }

    public function test_cross_intake_route_is_not_found_and_verified_page_has_no_form(): void
    {
        [$finance, $vendor, $department, $category] = $this->businessData();
        $intake = $this->intake($finance);
        $other = $this->intake($finance);

        $this->actingAs($finance)
            ->post(route('invoice-intakes.documents.verify', [$intake->intakeBatch, $other]), $this->payload($vendor, $department, $category))
            ->assertNotFound();

        $this->app->make(DocumentIntakeVerificationService::class)
            ->verify($intake, $this->payload($vendor, $department, $category), $finance);

        $this->actingAs($finance)
            ->get(route('invoice-intakes.documents.show', [$intake->intakeBatch, $intake]))
            ->assertOk()
            ->assertSee('Supplier Invoice draft created')
            ->assertSee('has not been submitted for approval')
            ->assertDontSee('Verify and create draft');
    }

    /** @return array{User, Vendor, Department, SpendCategory} */
    private function businessData(): array
    {
        return [
            User::factory()->create(['role' => UserRole::Finance]),
            Vendor::factory()->create(),
            Department::factory()->create(),
            SpendCategory::factory()->create(),
        ];
    }

    /** @param array<string, mixed> $candidate */
    private function intake(User $uploader, array $candidate = [], DocumentIntakeStatus $status = DocumentIntakeStatus::NeedsVerification): DocumentIntake
    {
        $path = 'document-intakes/tests/'.Str::uuid().'.pdf';
        Storage::disk('local')->put($path, "%PDF-1.4\ninvoice\n%%EOF");
        $batch = IntakeBatch::create(['uploaded_by' => $uploader->id, 'submission_key' => Str::uuid()->toString()]);

        return $batch->documentIntakes()->create([
            'original_name' => 'invoice.pdf',
            'disk' => 'local',
            'path' => $path,
            'mime_type' => 'application/pdf',
            'size' => 24,
            'document_type' => 'SUPPLIER_INVOICE',
            'status' => $status,
            'extraction_payload' => array_replace([
                'vendor_name' => 'Candidate Vendor',
                'invoice_no' => 'AI-100',
                'invoice_date' => '2026-09-15',
                'due_date' => '2026-10-15',
                'currency' => 'MYR',
                'subtotal' => '100.00',
                'tax_amount' => '6.00',
                'total_amount' => '106.00',
                'line_items' => [[
                    'description' => 'Extracted service',
                    'quantity' => '1.0000',
                    'unit_price' => '100.00',
                    'subtotal' => '100.00',
                ]],
            ], $candidate),
            'extraction_warnings' => [],
            'extracted_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(Vendor $vendor, Department $department, SpendCategory $category, array $overrides = []): array
    {
        return array_replace([
            'vendor_id' => $vendor->id,
            'invoice_no' => 'VERIFY-100',
            'department_id' => $department->id,
            'category_id' => $category->id,
            'invoice_date' => '2026-09-15',
            'due_date' => '2026-10-15',
            'description' => 'Verified supplier services.',
            'tax_amount' => '0.13',
            'items' => [
                ['description' => 'Consulting hours', 'quantity' => '1.2345', 'unit_price' => '10.01'],
                ['description' => 'Usage adjustment', 'quantity' => '0.0001', 'unit_price' => '100.00'],
            ],
        ], $overrides);
    }
}
