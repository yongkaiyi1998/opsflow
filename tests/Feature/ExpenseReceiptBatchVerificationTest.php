<?php

namespace Tests\Feature;

use App\DocumentIntakeStatus;
use App\ExpenseClaimStatus;
use App\IntakeDocumentType;
use App\MasterDataStatus;
use App\Models\Department;
use App\Models\DocumentIntake;
use App\Models\ExpenseClaim;
use App\Models\IntakeBatch;
use App\Models\SpendCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ExpenseReceiptBatchVerificationTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_owner_creates_one_draft_item_per_receipt_with_corrected_authoritative_values(): void
    {
        $department = Department::factory()->create();
        $owner = User::factory()->create(['department_id' => $department]);
        $category = SpendCategory::factory()->create();
        $batch = $this->receiptBatch($owner, 2);
        $documents = $batch->documentIntakes;
        $payload = $this->payload($documents->all(), $category);
        $payload['receipts'][$documents[0]->id] = ['category_id' => $category->id, 'expense_date' => '2026-09-01', 'merchant' => 'Corrected Merchant', 'description' => 'Corrected meal', 'amount' => '25.10', 'tax_amount' => '1.10'];
        $payload['receipts'][$documents[1]->id]['amount'] = '10.05';
        $payload['receipts'][$documents[1]->id]['tax_amount'] = '0.50';
        $payload['employee_id'] = User::factory()->create()->id;
        $payload['department_id'] = Department::factory()->create()->id;
        $payload['total_amount'] = '0.01';
        $payload['status'] = ExpenseClaimStatus::Approved->value;

        $this->actingAs($owner)->post(route('expense-receipt-intakes.verification.store', $batch), $payload)
            ->assertRedirect(route('expense-receipt-intakes.show', $batch));

        $claim = ExpenseClaim::query()->with('items.attachments')->sole();
        $this->assertSame($owner->id, $claim->employee_id);
        $this->assertSame($department->id, $claim->department_id);
        $this->assertSame(ExpenseClaimStatus::Draft, $claim->status);
        $this->assertSame('35.15', $claim->total_amount);
        $this->assertSame(2, $claim->items->count());
        $this->assertSame('Corrected Merchant', $claim->items[0]->merchant);
        $this->assertSame('25.10', $claim->items[0]->amount);
        $this->assertSame('1.10', $claim->items[0]->tax_amount);
        $this->assertDatabaseCount('approval_instances', 0);
        $this->assertSame($claim->id, $batch->fresh()->expense_claim_id);

        foreach ($documents as $index => $document) {
            $verified = $document->fresh();
            $item = $claim->items[$index];
            $attachment = $item->attachments->sole();
            $this->assertSame(DocumentIntakeStatus::Verified, $verified->status);
            $this->assertSame($item->id, $verified->expense_item_id);
            $this->assertSame($attachment->id, $verified->expense_item_attachment_id);
            $this->assertSame($document->path, $attachment->path);
            $this->assertSame($owner->id, $attachment->uploaded_by);
            Storage::disk('local')->assertExists($document->path);
        }

        $receiptAttachment = $claim->items[0]->attachments->sole();
        $this->actingAs($owner)->get(route('attachments.download', $receiptAttachment))->assertOk();
        $this->actingAs($this->employee())->get(route('attachments.download', $receiptAttachment))->assertForbidden();
    }

    public function test_other_users_and_admin_cannot_verify_an_owners_receipt_batch(): void
    {
        $owner = $this->employee();
        $other = $this->employee();
        $admin = User::factory()->admin()->create(['department_id' => Department::factory()]);
        $category = SpendCategory::factory()->create();
        $batch = $this->receiptBatch($owner);
        $payload = $this->payload($batch->documentIntakes->all(), $category);

        $this->actingAs($other)->get(route('expense-receipt-intakes.verification.create', $batch))->assertForbidden();
        $this->actingAs($other)->post(route('expense-receipt-intakes.verification.store', $batch), $payload)->assertForbidden();
        $this->actingAs($admin)->post(route('expense-receipt-intakes.verification.store', $batch), $payload)->assertForbidden();
        $this->assertDatabaseCount('expense_claims', 0);
    }

    public function test_repeated_verification_returns_existing_claim_without_duplicates(): void
    {
        $owner = $this->employee();
        $category = SpendCategory::factory()->create();
        $batch = $this->receiptBatch($owner, 2);
        $payload = $this->payload($batch->documentIntakes->all(), $category);

        $this->actingAs($owner)->post(route('expense-receipt-intakes.verification.store', $batch), $payload)->assertRedirect();
        $this->actingAs($owner)->post(route('expense-receipt-intakes.verification.store', $batch), $payload)->assertRedirect();

        $this->assertDatabaseCount('expense_claims', 1);
        $this->assertDatabaseCount('expense_items', 2);
        $this->assertDatabaseCount('attachments', 2);
    }

    public function test_non_ready_or_cross_batch_receipts_are_rejected_atomically(): void
    {
        $owner = $this->employee();
        $category = SpendCategory::factory()->create();
        $batch = $this->receiptBatch($owner, 2);
        $otherBatch = $this->receiptBatch($owner);
        $batch->documentIntakes[1]->update(['status' => DocumentIntakeStatus::Processing]);
        $payload = $this->payload($batch->documentIntakes->all(), $category);

        $this->actingAs($owner)->post(route('expense-receipt-intakes.verification.store', $batch), $payload)->assertSessionHasErrors('verification');
        $this->assertDatabaseCount('expense_claims', 0);

        $batch->documentIntakes[1]->update(['status' => DocumentIntakeStatus::NeedsVerification]);
        unset($payload['receipts'][$batch->documentIntakes[1]->id]);
        $payload['receipts'][$otherBatch->documentIntakes->sole()->id] = $payload['receipts'][$batch->documentIntakes[0]->id];

        $this->actingAs($owner)->post(route('expense-receipt-intakes.verification.store', $batch), $payload)->assertSessionHasErrors('receipts');
        $this->assertDatabaseCount('expense_claims', 0);
    }

    public function test_inactive_category_and_missing_source_fail_without_changing_intake(): void
    {
        $owner = $this->employee();
        $category = SpendCategory::factory()->create();
        $batch = $this->receiptBatch($owner);
        $document = $batch->documentIntakes->sole();
        $payload = $this->payload([$document], $category);
        $category->update(['status' => MasterDataStatus::Inactive]);

        $this->actingAs($owner)->post(route('expense-receipt-intakes.verification.store', $batch), $payload)
            ->assertSessionHasErrors("receipts.{$document->id}.category_id");

        $category->update(['status' => MasterDataStatus::Active]);
        Storage::disk('local')->delete($document->path);
        $this->actingAs($owner)->post(route('expense-receipt-intakes.verification.store', $batch), $payload)
            ->assertSessionHasErrors('verification');

        $this->assertDatabaseCount('expense_claims', 0);
        $this->assertSame(DocumentIntakeStatus::NeedsVerification, $document->fresh()->status);
        $this->assertNull($document->fresh()->expense_item_id);
    }

    public function test_deleting_verified_receipt_metadata_preserves_private_intake_source(): void
    {
        $owner = $this->employee();
        $category = SpendCategory::factory()->create();
        $batch = $this->receiptBatch($owner);
        $document = $batch->documentIntakes->sole();

        $this->actingAs($owner)
            ->post(route('expense-receipt-intakes.verification.store', $batch), $this->payload([$document], $category))
            ->assertRedirect();

        $attachment = ExpenseClaim::query()->sole()->items()->sole()->attachments()->sole();
        $this->actingAs($owner)->delete(route('attachments.destroy', $attachment))->assertRedirect();

        $this->assertDatabaseMissing('attachments', ['id' => $attachment->id]);
        $this->assertNull($document->fresh()->expense_item_attachment_id);
        Storage::disk('local')->assertExists($document->path);
        $this->actingAs($owner)
            ->get(route('expense-receipt-intakes.documents.original', [$batch, $document]))
            ->assertOk();
    }

    public function test_deleting_verified_draft_preserves_original_intake_receipt(): void
    {
        $owner = $this->employee();
        $category = SpendCategory::factory()->create();
        $batch = $this->receiptBatch($owner);
        $document = $batch->documentIntakes->sole();

        $this->actingAs($owner)
            ->post(route('expense-receipt-intakes.verification.store', $batch), $this->payload([$document], $category))
            ->assertRedirect();

        $claim = ExpenseClaim::query()->sole();
        $this->actingAs($owner)->delete(route('expense-claims.destroy', $claim))->assertRedirect();

        $this->assertDatabaseMissing('expense_claims', ['id' => $claim->id]);
        Storage::disk('local')->assertExists($document->path);
        $this->assertSame(DocumentIntakeStatus::Verified, $document->fresh()->status);
    }

    private function employee(): User
    {
        return User::factory()->create(['department_id' => Department::factory()]);
    }

    private function receiptBatch(User $owner, int $count = 1): IntakeBatch
    {
        $batch = IntakeBatch::create(['uploaded_by' => $owner->id, 'submission_key' => Str::uuid()->toString()]);

        foreach (range(1, $count) as $number) {
            $path = "document-intakes/tests/receipt-{$batch->id}-{$number}.pdf";
            Storage::disk('local')->put($path, 'private receipt');
            $batch->documentIntakes()->create([
                'original_name' => "receipt-{$number}.pdf", 'disk' => 'local', 'path' => $path,
                'mime_type' => 'application/pdf', 'size' => 15,
                'document_type' => IntakeDocumentType::ExpenseReceipt,
                'status' => DocumentIntakeStatus::NeedsVerification,
                'extraction_payload' => ['merchant' => "Candidate {$number}", 'transaction_date' => '2026-09-01', 'description' => "Candidate receipt {$number}", 'amount' => '9.99', 'tax_amount' => '0.99'],
            ]);
        }

        return $batch->load('documentIntakes');
    }

    /** @param list<DocumentIntake> $documents */
    private function payload(array $documents, SpendCategory $category): array
    {
        $receipts = [];
        foreach ($documents as $document) {
            $receipts[$document->id] = ['category_id' => $category->id, 'expense_date' => '2026-09-01', 'merchant' => 'Confirmed merchant', 'description' => 'Confirmed expense', 'amount' => '10.00', 'tax_amount' => '1.00'];
        }

        return ['title' => 'Verified receipt batch', 'description' => 'Created from reviewed private receipts.', 'receipts' => $receipts];
    }
}
