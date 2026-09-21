<?php

namespace Tests\Feature;

use App\DocumentIntakeStatus;
use App\IntakeDocumentType;
use App\MasterDataStatus;
use App\Models\Department;
use App\Models\DocumentIntake;
use App\Models\IntakeBatch;
use App\Models\PurchaseRequest;
use App\Models\SpendCategory;
use App\Models\User;
use App\Models\Vendor;
use App\PurchaseRequestStatus;
use App\Services\PurchaseQuotationVerificationService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class PurchaseQuotationVerificationTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_owner_verifies_corrected_candidate_into_one_authoritative_purchase_request_draft(): void
    {
        $department = Department::factory()->create();
        $owner = User::factory()->create(['department_id' => $department]);
        $vendor = Vendor::factory()->create();
        $category = SpendCategory::factory()->create();
        [$batch, $document] = $this->quotation($owner);
        $payload = $this->payload($vendor, $category);
        $payload += [
            'requester_id' => User::factory()->create()->id,
            'department_id' => Department::factory()->create()->id,
            'subtotal' => '0.01',
            'total_amount' => '0.02',
            'status' => PurchaseRequestStatus::Approved->value,
        ];

        $this->actingAs($owner)
            ->post(route('purchase-quotation-intakes.documents.verification.store', [$batch, $document]), $payload)
            ->assertRedirect(route('purchase-quotation-intakes.documents.show', [$batch, $document]));

        $purchaseRequest = PurchaseRequest::query()->with(['items', 'attachments'])->sole();
        $verified = $document->fresh();
        $attachment = $purchaseRequest->attachments->sole();

        $this->assertSame($owner->id, $purchaseRequest->requester_id);
        $this->assertSame($department->id, $purchaseRequest->department_id);
        $this->assertSame($vendor->id, $purchaseRequest->vendor_id);
        $this->assertSame($category->id, $purchaseRequest->category_id);
        $this->assertSame(PurchaseRequestStatus::Draft, $purchaseRequest->status);
        $this->assertSame('24.80', $purchaseRequest->subtotal);
        $this->assertSame('1.20', $purchaseRequest->tax_amount);
        $this->assertSame('26.00', $purchaseRequest->total_amount);
        $this->assertSame([2, 1], $purchaseRequest->items->pluck('quantity')->all());
        $this->assertSame(['20.50', '4.30'], $purchaseRequest->items->pluck('subtotal')->all());
        $this->assertDatabaseCount('approval_instances', 0);
        $this->assertSame(DocumentIntakeStatus::Verified, $verified->status);
        $this->assertSame($purchaseRequest->id, $verified->purchase_request_id);
        $this->assertSame($attachment->id, $verified->purchase_request_attachment_id);
        $this->assertSame($owner->id, $verified->verified_by);
        $this->assertNotNull($verified->verified_at);
        $this->assertSame($document->path, $attachment->path);
        Storage::disk('local')->assertExists($document->path);

        $this->actingAs($owner)->get(route('attachments.download', $attachment))->assertOk();
        $this->actingAs($this->employee())->get(route('attachments.download', $attachment))->assertForbidden();
    }

    public function test_repeated_and_stale_verification_returns_the_existing_draft_without_duplicates(): void
    {
        $owner = $this->employee();
        $vendor = Vendor::factory()->create();
        $category = SpendCategory::factory()->create();
        [$batch, $document] = $this->quotation($owner);
        $payload = $this->payload($vendor, $category);

        $this->actingAs($owner)->post(route('purchase-quotation-intakes.documents.verification.store', [$batch, $document]), $payload)->assertRedirect();
        $this->actingAs($owner)->post(route('purchase-quotation-intakes.documents.verification.store', [$batch, $document]), $payload)->assertRedirect();

        $staleResult = app(PurchaseQuotationVerificationService::class)->verify($document, $payload, $owner);

        $this->assertSame(PurchaseRequest::query()->sole()->id, $staleResult->id);
        $this->assertDatabaseCount('purchase_requests', 1);
        $this->assertDatabaseCount('purchase_request_items', 2);
        $this->assertDatabaseCount('attachments', 1);
    }

    public function test_cross_user_and_cross_batch_verification_are_denied(): void
    {
        $owner = $this->employee();
        $other = $this->employee();
        $vendor = Vendor::factory()->create();
        $category = SpendCategory::factory()->create();
        [$batch, $document] = $this->quotation($owner);
        [$otherBatch] = $this->quotation($owner);

        $this->actingAs($other)
            ->get(route('purchase-quotation-intakes.documents.verification.create', [$batch, $document]))
            ->assertForbidden();
        $this->actingAs($other)
            ->post(route('purchase-quotation-intakes.documents.verification.store', [$batch, $document]), $this->payload($vendor, $category))
            ->assertForbidden();
        $this->actingAs($owner)
            ->post(route('purchase-quotation-intakes.documents.verification.store', [$otherBatch, $document]), $this->payload($vendor, $category))
            ->assertNotFound();
        $this->assertDatabaseCount('purchase_requests', 0);
    }

    public function test_only_needs_verification_quotation_can_create_a_draft(): void
    {
        $owner = $this->employee();
        $vendor = Vendor::factory()->create();
        $category = SpendCategory::factory()->create();

        foreach ([
            DocumentIntakeStatus::Pending,
            DocumentIntakeStatus::Processing,
            DocumentIntakeStatus::Failed,
            DocumentIntakeStatus::Skipped,
            DocumentIntakeStatus::Verified,
        ] as $status) {
            [$batch, $document] = $this->quotation($owner, $status);
            $this->actingAs($owner)
                ->post(route('purchase-quotation-intakes.documents.verification.store', [$batch, $document]), $this->payload($vendor, $category))
                ->assertSessionHasErrors('verification');
        }

        $this->assertDatabaseCount('purchase_requests', 0);
    }

    public function test_form_validation_enforces_integer_quantity_and_active_master_data(): void
    {
        $owner = $this->employee();
        $vendor = Vendor::factory()->create();
        $category = SpendCategory::factory()->create();
        [$batch, $document] = $this->quotation($owner);

        $fractional = $this->payload($vendor, $category);
        $fractional['items'][0]['quantity'] = '1.5';
        $this->actingAs($owner)
            ->post(route('purchase-quotation-intakes.documents.verification.store', [$batch, $document]), $fractional)
            ->assertSessionHasErrors('items.0.quantity');

        $vendor->update(['status' => MasterDataStatus::Inactive]);
        $category->update(['status' => MasterDataStatus::Inactive]);
        $this->actingAs($owner)
            ->post(route('purchase-quotation-intakes.documents.verification.store', [$batch, $document]), $this->payload($vendor, $category))
            ->assertSessionHasErrors(['vendor_id', 'category_id']);

        $this->assertDatabaseCount('purchase_requests', 0);
        $this->assertSame(DocumentIntakeStatus::NeedsVerification, $document->fresh()->status);
    }

    public function test_failed_creation_preserves_private_source_and_intake_state(): void
    {
        $department = Department::factory()->create(['status' => MasterDataStatus::Inactive]);
        $owner = User::factory()->create(['department_id' => $department]);
        $vendor = Vendor::factory()->create();
        $category = SpendCategory::factory()->create();
        [$batch, $document] = $this->quotation($owner);

        $this->actingAs($owner)
            ->post(route('purchase-quotation-intakes.documents.verification.store', [$batch, $document]), $this->payload($vendor, $category))
            ->assertSessionHasErrors('requester');

        $this->assertDatabaseCount('purchase_requests', 0);
        $this->assertDatabaseCount('attachments', 0);
        $this->assertSame(DocumentIntakeStatus::NeedsVerification, $document->fresh()->status);
        $this->assertNull($document->fresh()->purchase_request_id);
        Storage::disk('local')->assertExists($document->path);
    }

    public function test_verified_result_is_read_only_and_deleting_generated_draft_preserves_intake_source(): void
    {
        $owner = $this->employee();
        $vendor = Vendor::factory()->create();
        $category = SpendCategory::factory()->create();
        [$batch, $document] = $this->quotation($owner);

        $this->actingAs($owner)
            ->post(route('purchase-quotation-intakes.documents.verification.store', [$batch, $document]), $this->payload($vendor, $category))
            ->assertRedirect();

        $purchaseRequest = PurchaseRequest::query()->sole();
        $this->actingAs($owner)
            ->get(route('purchase-quotation-intakes.documents.show', [$batch, $document]))
            ->assertOk()
            ->assertSee($purchaseRequest->request_no)
            ->assertSee('Open Purchase Request')
            ->assertDontSee('Create Purchase Request Draft');
        $this->actingAs($owner)
            ->get(route('purchase-quotation-intakes.documents.verification.create', [$batch, $document]))
            ->assertRedirect(route('purchase-quotation-intakes.documents.show', [$batch, $document]));

        $this->actingAs($owner)->delete(route('purchase-requests.destroy', $purchaseRequest))->assertRedirect();

        $this->assertDatabaseMissing('purchase_requests', ['id' => $purchaseRequest->id]);
        $this->assertSame(DocumentIntakeStatus::Verified, $document->fresh()->status);
        $this->assertNull($document->fresh()->purchase_request_id);
        Storage::disk('local')->assertExists($document->path);
        $this->actingAs($owner)
            ->get(route('purchase-quotation-intakes.documents.original', [$batch, $document]))
            ->assertOk();
    }

    public function test_deleting_generated_attachment_preserves_private_quotation_source(): void
    {
        $owner = $this->employee();
        $vendor = Vendor::factory()->create();
        $category = SpendCategory::factory()->create();
        [$batch, $document] = $this->quotation($owner);

        $this->actingAs($owner)
            ->post(route('purchase-quotation-intakes.documents.verification.store', [$batch, $document]), $this->payload($vendor, $category))
            ->assertRedirect();

        $attachment = PurchaseRequest::query()->sole()->attachments()->sole();
        $this->actingAs($owner)->delete(route('attachments.destroy', $attachment))->assertRedirect();

        $this->assertDatabaseMissing('attachments', ['id' => $attachment->id]);
        $this->assertNull($document->fresh()->purchase_request_attachment_id);
        Storage::disk('local')->assertExists($document->path);
        $this->actingAs($owner)
            ->get(route('purchase-quotation-intakes.documents.original', [$batch, $document]))
            ->assertOk();
    }

    public function test_verification_page_escapes_candidate_content_and_shows_advisory_vendor_match(): void
    {
        $owner = $this->employee();
        Vendor::factory()->create(['name' => 'Acme Supplies Sdn Bhd']);
        [, $document] = $this->quotation($owner, candidateOverrides: [
            'vendor_name' => '<script>alert(1)</script> Acme Supplies',
        ]);
        $batch = $document->intakeBatch;

        $this->actingAs($owner)
            ->get(route('purchase-quotation-intakes.documents.verification.create', [$batch, $document]))
            ->assertOk()
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('Creating the draft does not submit it for approval.');
    }

    private function employee(): User
    {
        return User::factory()->create(['department_id' => Department::factory()]);
    }

    /** @param array<string, mixed> $candidateOverrides
     * @return array{IntakeBatch, DocumentIntake}
     */
    private function quotation(
        User $owner,
        DocumentIntakeStatus $status = DocumentIntakeStatus::NeedsVerification,
        array $candidateOverrides = [],
    ): array {
        $batch = IntakeBatch::create([
            'uploaded_by' => $owner->id,
            'submission_key' => Str::uuid()->toString(),
        ]);
        $path = "document-intakes/tests/quotation-{$batch->id}.pdf";
        Storage::disk('local')->put($path, 'private quotation');
        $document = $batch->documentIntakes()->create([
            'original_name' => 'quotation.pdf',
            'disk' => 'local',
            'path' => $path,
            'mime_type' => 'application/pdf',
            'size' => 17,
            'document_type' => IntakeDocumentType::PurchaseQuotation,
            'status' => $status,
            'extraction_payload' => array_replace([
                'vendor_name' => 'Acme Supplies',
                'quotation_no' => 'QT-100',
                'quotation_date' => '2026-09-10',
                'valid_until' => '2026-10-10',
                'currency' => 'MYR',
                'subtotal' => '999.00',
                'tax_amount' => '99.00',
                'total_amount' => '1098.00',
                'line_items' => [
                    ['description' => 'Candidate laptop', 'quantity' => '1', 'unit_price' => '999.00', 'subtotal' => '999.00'],
                ],
            ], $candidateOverrides),
            'extraction_warnings' => [['code' => 'total_mismatch', 'message' => 'Review the extracted total.']],
        ]);

        return [$batch, $document];
    }

    /** @return array<string, mixed> */
    private function payload(Vendor $vendor, SpendCategory $category): array
    {
        return [
            'title' => 'Corrected office equipment request',
            'description' => 'Purchase equipment from the reviewed quotation.',
            'vendor_id' => $vendor->id,
            'category_id' => $category->id,
            'needed_by_date' => null,
            'tax_amount' => '1.20',
            'items' => [
                ['description' => 'Corrected laptop', 'quantity' => 2, 'unit_price' => '10.25'],
                ['description' => 'Corrected adapter', 'quantity' => 1, 'unit_price' => '4.30'],
            ],
        ];
    }
}
