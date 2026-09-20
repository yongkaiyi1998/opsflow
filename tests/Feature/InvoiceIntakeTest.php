<?php

namespace Tests\Feature;

use App\DocumentIntakeStatus;
use App\Models\AiInteraction;
use App\Models\DocumentIntake;
use App\Models\IntakeBatch;
use App\Models\User;
use App\Services\InvoiceIntakeService;
use App\UserRole;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

class InvoiceIntakeTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_finance_user_can_create_a_multi_file_pending_batch(): void
    {
        Storage::fake('local');
        $finance = $this->financeUser();
        $submissionKey = Str::uuid()->toString();

        $response = $this->actingAs($finance)->post(route('invoice-intakes.store'), [
            'submission_key' => $submissionKey,
            'documents' => [
                UploadedFile::fake()->create('invoice-one.pdf', 100, 'application/pdf'),
                UploadedFile::fake()->create('invoice-two.jpg', 120, 'image/jpeg'),
                UploadedFile::fake()->create('invoice-three.png', 140, 'image/png'),
            ],
        ]);

        $batch = IntakeBatch::query()->sole();
        $response->assertRedirect(route('invoice-intakes.show', $batch));
        $this->assertSame($finance->id, $batch->uploaded_by);
        $this->assertSame($submissionKey, $batch->submission_key);
        $this->assertSame(3, $batch->documentIntakes()->count());
        $this->assertSame(
            [DocumentIntakeStatus::Pending],
            $batch->documentIntakes()->pluck('status')->unique()->values()->all(),
        );

        foreach ($batch->documentIntakes as $document) {
            Storage::disk('local')->assertExists($document->path);
            $this->assertStringStartsWith('document-intakes/', $document->path);
        }
    }

    public function test_admin_user_can_create_a_batch_while_ai_is_disabled(): void
    {
        Storage::fake('local');
        config()->set('ai.enabled', false);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('invoice-intakes.store'), $this->validPayload())
            ->assertRedirect();

        $this->assertSame(1, IntakeBatch::query()->count());
        $this->assertSame(1, DocumentIntake::query()->count());
        $this->assertSame(0, AiInteraction::query()->count());
    }

    public function test_employee_cannot_list_upload_view_or_download_intake_documents(): void
    {
        Storage::fake('local');
        $finance = $this->financeUser();
        $employee = User::factory()->create();
        $batch = $this->createBatch($finance);
        $document = $batch->documentIntakes->firstOrFail();

        $this->actingAs($employee)->get(route('invoice-intakes.index'))->assertForbidden();
        $this->actingAs($employee)->get(route('invoice-intakes.create'))->assertForbidden();
        $this->actingAs($employee)->post(route('invoice-intakes.store'), $this->validPayload())->assertForbidden();
        $this->actingAs($employee)->get(route('invoice-intakes.show', $batch))->assertForbidden();
        $this->actingAs($employee)->get(route('invoice-intakes.documents.original', [$batch, $document]))->assertForbidden();
    }

    public function test_batch_rejects_more_than_twenty_documents_without_storing_files(): void
    {
        Storage::fake('local');
        $documents = [];

        for ($index = 1; $index <= 21; $index++) {
            $documents[] = UploadedFile::fake()->create("invoice-{$index}.pdf", 10, 'application/pdf');
        }

        $this->actingAs($this->financeUser())->post(route('invoice-intakes.store'), [
            'submission_key' => Str::uuid()->toString(),
            'documents' => $documents,
        ])->assertSessionHasErrors('documents');

        $this->assertSame(0, IntakeBatch::query()->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_batch_rejects_unsupported_file_type(): void
    {
        Storage::fake('local');

        $this->actingAs($this->financeUser())->post(route('invoice-intakes.store'), [
            'submission_key' => Str::uuid()->toString(),
            'documents' => [UploadedFile::fake()->create('payload.php', 10, 'application/x-php')],
        ])->assertSessionHasErrors('documents.0');

        $this->assertSame(0, DocumentIntake::query()->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_batch_rejects_oversized_file(): void
    {
        Storage::fake('local');

        $this->actingAs($this->financeUser())->post(route('invoice-intakes.store'), [
            'submission_key' => Str::uuid()->toString(),
            'documents' => [UploadedFile::fake()->create('large.pdf', 10241, 'application/pdf')],
        ])->assertSessionHasErrors('documents.0');

        $this->assertSame(0, DocumentIntake::query()->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_service_boundary_enforces_batch_count_and_file_size_limits(): void
    {
        Storage::fake('local');
        $service = $this->app->make(InvoiceIntakeService::class);
        $finance = $this->financeUser();
        $tooMany = array_fill(0, 21, UploadedFile::fake()->create('invoice.pdf', 10, 'application/pdf'));

        foreach ([$tooMany, [UploadedFile::fake()->create('large.pdf', 10241, 'application/pdf')]] as $files) {
            try {
                $service->createBatch($files, Str::uuid()->toString(), $finance);
                $this->fail('Expected the service boundary to reject invalid upload limits.');
            } catch (ValidationException $exception) {
                $this->assertNotEmpty($exception->errors());
            }
        }

        $this->assertDatabaseCount('intake_batches', 0);
        $this->assertDatabaseCount('document_intakes', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_authorized_user_can_download_private_original(): void
    {
        Storage::fake('local');
        $finance = $this->financeUser();
        $batch = $this->createBatch($finance, 'supplier invoice.pdf');
        $document = $batch->documentIntakes->firstOrFail();

        $this->actingAs($finance)
            ->get(route('invoice-intakes.documents.original', [$batch, $document]))
            ->assertOk()
            ->assertDownload('supplier invoice.pdf');

        $this->assertSame('local', $document->disk);
        Storage::disk('local')->assertExists($document->path);
    }

    public function test_document_from_another_batch_is_not_resolved_through_parent_route(): void
    {
        Storage::fake('local');
        $finance = $this->financeUser();
        $firstBatch = $this->createBatch($finance, 'first.pdf');
        $secondBatch = $this->createBatch($finance, 'second.pdf');
        $secondDocument = $secondBatch->documentIntakes->firstOrFail();

        $this->actingAs($finance)
            ->get(route('invoice-intakes.documents.original', [$firstBatch, $secondDocument]))
            ->assertNotFound();
    }

    public function test_repeated_submission_key_does_not_duplicate_batch_documents_or_files(): void
    {
        Storage::fake('local');
        $finance = $this->financeUser();
        $submissionKey = Str::uuid()->toString();

        $first = $this->actingAs($finance)->post(route('invoice-intakes.store'), $this->validPayload($submissionKey, 'first.pdf'));
        $second = $this->actingAs($finance)->post(route('invoice-intakes.store'), $this->validPayload($submissionKey, 'retry.pdf'));

        $batch = IntakeBatch::query()->sole();
        $first->assertRedirect(route('invoice-intakes.show', $batch));
        $second->assertRedirect(route('invoice-intakes.show', $batch));
        $this->assertSame(1, $batch->documentIntakes()->count());
        $this->assertSame(1, count(Storage::disk('local')->allFiles('document-intakes')));
    }

    public function test_database_failure_rolls_back_batch_and_removes_stored_files(): void
    {
        Storage::fake('local');
        $createdDocuments = 0;
        DocumentIntake::creating(function () use (&$createdDocuments): void {
            $createdDocuments++;

            if ($createdDocuments === 2) {
                throw new RuntimeException('Simulated metadata failure.');
            }
        });

        try {
            $this->app->make(InvoiceIntakeService::class)->createBatch(
                [
                    UploadedFile::fake()->create('first.pdf', 10, 'application/pdf'),
                    UploadedFile::fake()->create('second.pdf', 10, 'application/pdf'),
                ],
                Str::uuid()->toString(),
                $this->financeUser(),
            );
            $this->fail('Expected batch persistence to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated metadata failure.', $exception->getMessage());
        }

        $this->assertSame(0, IntakeBatch::query()->count());
        $this->assertSame(0, DocumentIntake::query()->count());
        $this->assertSame([], Storage::disk('local')->allFiles('document-intakes'));
    }

    public function test_batch_progress_is_derived_from_document_states(): void
    {
        Storage::fake('local');
        $batch = $this->createBatch($this->financeUser(), documents: 3);
        $documents = $batch->documentIntakes;
        $documents[0]->update(['status' => DocumentIntakeStatus::Verified]);
        $documents[1]->update(['status' => DocumentIntakeStatus::NeedsVerification]);
        $documents[2]->update(['status' => DocumentIntakeStatus::Failed]);
        $batch->load('documentIntakes');

        $counts = $batch->statusCounts();

        $this->assertSame(1, $counts[DocumentIntakeStatus::Verified->value]);
        $this->assertSame(1, $counts[DocumentIntakeStatus::NeedsVerification->value]);
        $this->assertSame(1, $counts[DocumentIntakeStatus::Failed->value]);
        $this->assertSame(DocumentIntakeStatus::NeedsVerification, $batch->derivedStatus());
    }

    public function test_filename_is_escaped_and_navigation_respects_roles(): void
    {
        Storage::fake('local');
        $finance = $this->financeUser();
        $employee = User::factory()->create();
        $batch = $this->createBatch($finance, 'invoice-<img src=x onerror=alert(1)>.pdf');

        $this->actingAs($finance)->get(route('invoice-intakes.show', $batch))
            ->assertSee('invoice-&lt;img src=x onerror=alert(1)&gt;.pdf', false)
            ->assertDontSee('invoice-<img src=x onerror=alert(1)>.pdf', false);
        $this->actingAs($finance)->get(route('dashboard'))->assertSee('Invoice Intake');
        $this->actingAs($employee)->get(route('dashboard'))->assertDontSee('Invoice Intake');
    }

    private function financeUser(): User
    {
        return User::factory()->create(['role' => UserRole::Finance]);
    }

    /** @return array{submission_key: string, documents: list<UploadedFile>} */
    private function validPayload(?string $submissionKey = null, string $filename = 'invoice.pdf'): array
    {
        return [
            'submission_key' => $submissionKey ?? Str::uuid()->toString(),
            'documents' => [UploadedFile::fake()->create($filename, 100, 'application/pdf')],
        ];
    }

    private function createBatch(User $user, string $filename = 'invoice.pdf', int $documents = 1): IntakeBatch
    {
        $files = [];

        for ($index = 0; $index < $documents; $index++) {
            $files[] = UploadedFile::fake()->create($documents === 1 ? $filename : "invoice-{$index}.pdf", 100, 'application/pdf');
        }

        return $this->app->make(InvoiceIntakeService::class)->createBatch(
            $files,
            Str::uuid()->toString(),
            $user,
        );
    }
}
