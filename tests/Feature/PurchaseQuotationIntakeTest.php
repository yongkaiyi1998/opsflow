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

class PurchaseQuotationIntakeTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config()->set('ai.enabled', false);
    }

    public function test_active_user_uploads_private_pdf_jpeg_and_png_quotations_without_creating_pr(): void
    {
        Queue::fake();
        $user = $this->employee();

        $response = $this->actingAs($user)->post(route('purchase-quotation-intakes.store'), [
            'submission_key' => Str::uuid()->toString(),
            'documents' => [
                UploadedFile::fake()->create('quote.pdf', 50, 'application/pdf'),
                UploadedFile::fake()->image('quote.jpg', 2, 2),
                UploadedFile::fake()->image('quote.png', 2, 2),
            ],
        ]);

        $batch = IntakeBatch::query()->sole();
        $response->assertRedirect(route('purchase-quotation-intakes.show', $batch));
        $this->assertSame($user->id, $batch->uploaded_by);
        $this->assertSame([IntakeDocumentType::PurchaseQuotation], $batch->documentIntakes()->pluck('document_type')->unique()->all());
        $this->assertSame([DocumentIntakeStatus::Pending], $batch->documentIntakes()->pluck('status')->unique()->all());
        $this->assertDatabaseCount('purchase_requests', 0);
        Queue::assertNothingPushed();

        foreach ($batch->documentIntakes as $document) {
            Storage::disk('local')->assertExists($document->path);
        }
    }

    public function test_quotation_batches_results_and_originals_are_owner_and_parent_scoped(): void
    {
        $owner = $this->employee();
        $other = $this->employee();
        $batch = $this->createBatch($owner);
        $document = $batch->documentIntakes->sole();

        $this->actingAs($owner)->get(route('purchase-quotation-intakes.show', $batch))->assertOk();
        $this->actingAs($owner)->get(route('purchase-quotation-intakes.documents.original', [$batch, $document]))->assertOk()->assertDownload('quotation.pdf');
        $this->actingAs($other)->get(route('purchase-quotation-intakes.show', $batch))->assertForbidden();
        $this->actingAs($other)->get(route('purchase-quotation-intakes.documents.show', [$batch, $document]))->assertForbidden();
        $this->actingAs($other)->get(route('purchase-quotation-intakes.documents.original', [$batch, $document]))->assertForbidden();

        $otherBatch = $this->createBatch($owner);
        $this->actingAs($owner)->get(route('purchase-quotation-intakes.documents.show', [$otherBatch, $document]))->assertNotFound();
    }

    public function test_invalid_and_oversized_quotations_are_rejected_without_files_or_records(): void
    {
        $user = $this->employee();

        foreach ([
            UploadedFile::fake()->create('payload.php', 10, 'application/x-php'),
            UploadedFile::fake()->create('large.pdf', 10241, 'application/pdf'),
        ] as $file) {
            $this->actingAs($user)->post(route('purchase-quotation-intakes.store'), [
                'submission_key' => Str::uuid()->toString(), 'documents' => [$file],
            ])->assertSessionHasErrors('documents.0');
        }

        $this->assertDatabaseCount('intake_batches', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_repeated_upload_is_idempotent_and_ai_enabled_upload_queues_once_per_document(): void
    {
        Queue::fake();
        $user = $this->employee();
        $key = Str::uuid()->toString();

        $this->actingAs($user)->post(route('purchase-quotation-intakes.store'), $this->payload($key, 'first.pdf'))->assertRedirect();
        $this->actingAs($user)->post(route('purchase-quotation-intakes.store'), $this->payload($key, 'retry.pdf'))->assertRedirect();
        $this->assertDatabaseCount('intake_batches', 1);
        $this->assertDatabaseCount('document_intakes', 1);
        $this->assertSame('first.pdf', DocumentIntake::query()->sole()->original_name);
        Queue::assertNothingPushed();

        config()->set('ai.enabled', true);
        $this->actingAs($user)->post(route('purchase-quotation-intakes.store'), $this->payload(filename: 'queued.pdf'))->assertRedirect();
        Queue::assertPushed(ProcessDocumentIntake::class, 1);
    }

    public function test_inactive_user_cannot_access_quotation_intake(): void
    {
        $user = User::factory()->inactive()->create(['department_id' => Department::factory()]);

        $this->actingAs($user)->get(route('purchase-quotation-intakes.index'))->assertRedirect(route('login'));
        $this->actingAs($user)->post(route('purchase-quotation-intakes.store'), $this->payload())->assertRedirect(route('login'));
    }

    private function employee(): User
    {
        return User::factory()->create(['department_id' => Department::factory()]);
    }

    private function createBatch(User $user): IntakeBatch
    {
        $this->actingAs($user)->post(route('purchase-quotation-intakes.store'), $this->payload())->assertRedirect();

        return IntakeBatch::query()->latest('id')->firstOrFail()->load('documentIntakes');
    }

    private function payload(?string $submissionKey = null, string $filename = 'quotation.pdf'): array
    {
        return [
            'submission_key' => $submissionKey ?? Str::uuid()->toString(),
            'documents' => [UploadedFile::fake()->create($filename, 50, 'application/pdf')],
        ];
    }
}
