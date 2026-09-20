<?php

namespace Tests\Feature;

use App\DocumentIntakeStatus;
use App\IntakeDocumentType;
use App\Jobs\ProcessDocumentIntake;
use App\Models\Department;
use App\Models\DocumentIntake;
use App\Models\IntakeBatch;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ExpenseReceiptIntakeTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config()->set('ai.enabled', false);
    }

    public function test_employee_can_upload_pdf_jpeg_and_png_receipts_for_themselves(): void
    {
        Queue::fake();
        $employee = $this->employee();

        $response = $this->actingAs($employee)->post(route('expense-receipt-intakes.store'), [
            'submission_key' => Str::uuid()->toString(),
            'documents' => [
                UploadedFile::fake()->create('meal.pdf', 50, 'application/pdf'),
                UploadedFile::fake()->image('taxi.jpg', 2, 2),
                UploadedFile::fake()->image('hotel.png', 2, 2),
            ],
        ]);

        $batch = IntakeBatch::query()->sole();
        $response->assertRedirect(route('expense-receipt-intakes.show', $batch));
        $this->assertSame($employee->id, $batch->uploaded_by);
        $this->assertSame(3, $batch->documentIntakes()->count());
        $this->assertSame(
            [IntakeDocumentType::ExpenseReceipt],
            $batch->documentIntakes()->pluck('document_type')->unique()->values()->all(),
        );
        $this->assertSame(
            [DocumentIntakeStatus::Pending],
            $batch->documentIntakes()->pluck('status')->unique()->values()->all(),
        );
        $this->assertDatabaseCount('expense_claims', 0);
        Queue::assertNothingPushed();

        foreach ($batch->documentIntakes as $document) {
            $this->assertSame('local', $document->disk);
            Storage::disk('local')->assertExists($document->path);
        }
    }

    public function test_user_without_expense_claim_creation_authority_cannot_access_receipt_intake(): void
    {
        $user = User::factory()->create(['department_id' => null]);

        $this->actingAs($user)->get(route('expense-receipt-intakes.index'))->assertForbidden();
        $this->actingAs($user)->get(route('expense-receipt-intakes.create'))->assertForbidden();
        $this->actingAs($user)->post(route('expense-receipt-intakes.store'), $this->validPayload())->assertForbidden();
        $this->assertDatabaseCount('intake_batches', 0);
    }

    public function test_receipt_batches_are_owner_scoped_across_lists_details_results_and_originals(): void
    {
        $owner = $this->employee();
        $otherEmployee = $this->employee();
        $admin = User::factory()->admin()->create(['department_id' => Department::factory()]);
        $batch = $this->createBatch($owner, 'private-receipt.pdf');
        $document = $batch->documentIntakes->sole();

        $this->actingAs($owner)->get(route('expense-receipt-intakes.index'))
            ->assertOk()
            ->assertSee('#000001');
        $this->actingAs($owner)->get(route('expense-receipt-intakes.show', $batch))
            ->assertOk()
            ->assertSee('private-receipt.pdf');
        $this->actingAs($otherEmployee)->get(route('expense-receipt-intakes.index'))
            ->assertOk()
            ->assertSee('No receipt batches yet');
        $this->actingAs($otherEmployee)->get(route('expense-receipt-intakes.show', $batch))->assertForbidden();
        $this->actingAs($admin)->get(route('expense-receipt-intakes.show', $batch))->assertForbidden();
        $this->actingAs($otherEmployee)->get(route('expense-receipt-intakes.documents.show', [$batch, $document]))->assertForbidden();
        $this->actingAs($otherEmployee)->get(route('expense-receipt-intakes.documents.original', [$batch, $document]))->assertForbidden();

        $this->actingAs($owner)
            ->get(route('expense-receipt-intakes.documents.original', [$batch, $document]))
            ->assertOk()
            ->assertDownload('private-receipt.pdf');
    }

    public function test_invalid_and_oversized_receipts_are_rejected_without_persistence(): void
    {
        $employee = $this->employee();

        foreach ([
            UploadedFile::fake()->create('payload.php', 10, 'application/x-php'),
            UploadedFile::fake()->create('large.pdf', 10241, 'application/pdf'),
        ] as $document) {
            $this->actingAs($employee)->post(route('expense-receipt-intakes.store'), [
                'submission_key' => Str::uuid()->toString(),
                'documents' => [$document],
            ])->assertSessionHasErrors('documents.0');
        }

        $this->assertDatabaseCount('intake_batches', 0);
        $this->assertDatabaseCount('document_intakes', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_repeated_submission_is_idempotent_and_keeps_ai_disabled_documents_pending(): void
    {
        Queue::fake();
        $employee = $this->employee();
        $submissionKey = Str::uuid()->toString();

        $this->actingAs($employee)->post(route('expense-receipt-intakes.store'), $this->validPayload($submissionKey, 'first.pdf'))
            ->assertRedirect();
        $this->actingAs($employee)->post(route('expense-receipt-intakes.store'), $this->validPayload($submissionKey, 'retry.pdf'))
            ->assertRedirect();

        $document = DocumentIntake::query()->sole();
        $this->assertDatabaseCount('intake_batches', 1);
        $this->assertSame('first.pdf', $document->original_name);
        $this->assertSame(DocumentIntakeStatus::Pending, $document->status);
        $this->assertSame(1, count(Storage::disk('local')->allFiles('document-intakes')));
        $this->assertDatabaseCount('ai_interactions', 0);
        $this->assertDatabaseCount('expense_claims', 0);
        Queue::assertNothingPushed();
    }

    public function test_ai_enabled_upload_queues_each_receipt_after_persistence(): void
    {
        config()->set('ai.enabled', true);
        Queue::fake();
        $employee = $this->employee();

        $this->actingAs($employee)->post(route('expense-receipt-intakes.store'), [
            'submission_key' => Str::uuid()->toString(),
            'documents' => [
                UploadedFile::fake()->createWithContent('one.pdf', "%PDF-1.4\none\n%%EOF"),
                UploadedFile::fake()->createWithContent('two.pdf', "%PDF-1.4\ntwo\n%%EOF"),
            ],
        ])->assertRedirect();

        $this->assertDatabaseCount('document_intakes', 2);
        Queue::assertPushed(ProcessDocumentIntake::class, 2);
    }

    public function test_parent_scope_and_document_type_prevent_cross_batch_and_cross_product_access(): void
    {
        $employee = $this->employee();
        $firstBatch = $this->createBatch($employee, 'first.pdf');
        $secondBatch = $this->createBatch($employee, 'second.pdf');
        $secondDocument = $secondBatch->documentIntakes->sole();

        $this->actingAs($employee)
            ->get(route('expense-receipt-intakes.documents.show', [$firstBatch, $secondDocument]))
            ->assertNotFound();

        $invoiceBatch = IntakeBatch::create([
            'uploaded_by' => $employee->id,
            'submission_key' => Str::uuid()->toString(),
        ]);
        $invoiceDocument = $invoiceBatch->documentIntakes()->create([
            'original_name' => 'invoice.pdf',
            'disk' => 'local',
            'path' => 'document-intakes/tests/invoice.pdf',
            'mime_type' => 'application/pdf',
            'size' => 10,
            'document_type' => IntakeDocumentType::SupplierInvoice,
            'status' => DocumentIntakeStatus::Pending,
        ]);

        $this->actingAs($employee)
            ->get(route('expense-receipt-intakes.documents.show', [$invoiceBatch, $invoiceDocument]))
            ->assertForbidden();
    }

    public function test_receipt_filename_is_escaped_and_navigation_is_available_to_eligible_employee(): void
    {
        $employee = $this->employee();
        $batch = $this->createBatch($employee, 'receipt-<img src=x onerror=alert(1)>.pdf');

        $this->actingAs($employee)->get(route('expense-receipt-intakes.show', $batch))
            ->assertSee('receipt-&lt;img src=x onerror=alert(1)&gt;.pdf', false)
            ->assertDontSee('receipt-<img src=x onerror=alert(1)>.pdf', false);
        $this->actingAs($employee)->get(route('dashboard'))->assertSee('Receipt Intake');
    }

    private function employee(): User
    {
        return User::factory()->create(['department_id' => Department::factory()]);
    }

    /** @return array{submission_key: string, documents: list<UploadedFile>} */
    private function validPayload(?string $submissionKey = null, string $filename = 'receipt.pdf'): array
    {
        return [
            'submission_key' => $submissionKey ?? Str::uuid()->toString(),
            'documents' => [UploadedFile::fake()->create($filename, 50, 'application/pdf')],
        ];
    }

    private function createBatch(User $employee, string $filename): IntakeBatch
    {
        $this->actingAs($employee)->post(
            route('expense-receipt-intakes.store'),
            $this->validPayload(filename: $filename),
        )->assertRedirect();

        return IntakeBatch::query()->latest('id')->firstOrFail()->load('documentIntakes');
    }
}
