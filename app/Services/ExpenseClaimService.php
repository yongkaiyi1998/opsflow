<?php

namespace App\Services;

use App\DocumentIntakeStatus;
use App\Exceptions\ApprovalRuntimeException;
use App\Exceptions\WorkflowResolutionException;
use App\ExpenseClaimStatus;
use App\IntakeDocumentType;
use App\MasterDataStatus;
use App\Models\Attachment;
use App\Models\DocumentIntake;
use App\Models\ExpenseClaim;
use App\Models\ExpenseItem;
use App\Models\IntakeBatch;
use App\Models\User;
use App\ReferenceType;
use App\Support\Money;
use App\WorkflowContext;
use App\WorkflowModuleType;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use LogicException;
use OverflowException;

class ExpenseClaimService
{
    public function __construct(
        private readonly ReferenceNumberGenerator $referenceNumbers,
        private readonly WorkflowResolver $workflowResolver,
        private readonly WorkflowEngine $workflowEngine,
        private readonly ApprovalService $approvalService,
        private readonly AuditService $auditService,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function create(array $attributes, User $user): ExpenseClaim
    {
        Gate::forUser($user)->authorize('create', ExpenseClaim::class);

        return DB::transaction(function () use ($attributes, $user): ExpenseClaim {
            $employee = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($employee)->authorize('create', ExpenseClaim::class);

            return $this->createDraft($attributes, $employee);
        }, 5);
    }

    /** @param array<string, mixed> $attributes */
    public function createFromReceiptBatch(IntakeBatch $batch, array $attributes, User $actor): ExpenseClaim
    {
        Gate::forUser($actor)->authorize('view', $batch);
        Gate::forUser($actor)->authorize('create', ExpenseClaim::class);

        if ($batch->expense_claim_id === null) {
            foreach ($batch->documentIntakes()->get() as $document) {
                $this->validatePrivateReceiptSource($document);
            }
        }

        return DB::transaction(function () use ($batch, $attributes, $actor): ExpenseClaim {
            $lockedBatch = IntakeBatch::query()->whereKey($batch->id)->lockForUpdate()->firstOrFail();
            $documents = DocumentIntake::query()
                ->where('intake_batch_id', $lockedBatch->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $employee = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();

            if (! $employee->isActive()) {
                throw new AuthorizationException;
            }

            $lockedBatch->setRelation('documentIntakes', $documents);
            Gate::forUser($employee)->authorize('view', $lockedBatch);
            Gate::forUser($employee)->authorize('create', ExpenseClaim::class);

            if ($lockedBatch->expense_claim_id !== null) {
                return ExpenseClaim::query()->findOrFail($lockedBatch->expense_claim_id)
                    ->load(['employee', 'department', 'items.category', 'items.attachments']);
            }

            if ($documents->isEmpty()
                || $documents->contains(fn (DocumentIntake $document): bool => $document->document_type !== IntakeDocumentType::ExpenseReceipt)
                || $documents->contains(fn (DocumentIntake $document): bool => $document->status !== DocumentIntakeStatus::NeedsVerification
                    || $document->expense_item_id !== null
                    || $document->expense_item_attachment_id !== null)) {
                throw ValidationException::withMessages([
                    'verification' => 'Every receipt must be ready for verification before creating the expense claim.',
                ]);
            }

            $submittedReceipts = collect($attributes['receipts'] ?? []);
            $submittedIds = $submittedReceipts->keys()->map(fn (mixed $id): int => (int) $id)->sort()->values();
            $documentIds = $documents->pluck('id')->map(fn (mixed $id): int => (int) $id)->sort()->values();

            if ($submittedIds->all() !== $documentIds->all()) {
                throw ValidationException::withMessages([
                    'receipts' => 'The submitted receipts do not match this batch. Reload and try again.',
                ]);
            }

            foreach ($documents as $document) {
                $this->validatePrivateReceiptMetadata($document);
            }

            $claimAttributes = [
                'title' => $attributes['title'] ?? '',
                'description' => $attributes['description'] ?? '',
                'items' => $documents->map(fn (DocumentIntake $document): array => $submittedReceipts->get((string) $document->id)
                    ?? $submittedReceipts->get($document->id))->all(),
            ];
            $claim = $this->createDraft($claimAttributes, $employee);

            foreach ($documents as $index => $document) {
                $item = $claim->items[$index];
                $attachment = $this->createReceiptAttachment($document, $item, $employee);
                $document->forceFill([
                    'status' => DocumentIntakeStatus::Verified,
                    'expense_item_id' => $item->id,
                    'expense_item_attachment_id' => $attachment->id,
                    'verified_by' => $employee->id,
                    'verified_at' => now(),
                    'processing_started_at' => null,
                    'failure_reason' => null,
                ])->save();
            }

            $lockedBatch->forceFill(['expense_claim_id' => $claim->id])->save();
            $this->auditService->logCreated($claim, $employee, [
                'claim_no' => $claim->claim_no,
                'department_id' => $claim->department_id,
                'total_amount' => $claim->total_amount,
                'status' => $claim->status,
            ], metadata: ['intake_batch_id' => $lockedBatch->id]);

            return $claim->load(['employee', 'department', 'items.category', 'items.attachments']);
        }, 5);
    }

    /** @param array<string, mixed> $attributes */
    public function update(ExpenseClaim $expenseClaim, array $attributes, User $user): ExpenseClaim
    {
        [$claim, $files] = DB::transaction(function () use ($expenseClaim, $attributes, $user): array {
            $lockedClaim = ExpenseClaim::query()->whereKey($expenseClaim->id)->lockForUpdate()->firstOrFail();
            $employee = User::query()->whereKey($lockedClaim->employee_id)->lockForUpdate()->firstOrFail();
            Gate::forUser($employee)->authorize('update', $lockedClaim);

            if ($employee->id !== $user->id) {
                throw new AuthorizationException;
            }

            if ($lockedClaim->lock_version !== (int) $attributes['lock_version']) {
                throw ValidationException::withMessages([
                    'lock_version' => 'This expense claim changed while you were editing it. Reload and try again.',
                ]);
            }

            $departmentId = $this->validateEmployeeDepartment($employee, true);
            [$items, $total] = $this->normalizeItems($attributes['items'] ?? [], true);
            $this->validateCategories($items);
            $existingItems = ExpenseItem::query()
                ->where('expense_claim_id', $lockedClaim->id)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $lockedClaim->setRelation('items', $existingItems->values());
            $oldValues = $this->auditValues($lockedClaim);
            $submittedIds = collect($items)->pluck('id')->filter()->map(fn ($id): int => (int) $id);

            if ($submittedIds->diff($existingItems->keys())->isNotEmpty()) {
                throw ValidationException::withMessages(['items' => 'One or more expense items do not belong to this claim.']);
            }

            foreach ($items as $item) {
                $id = isset($item['id']) ? (int) $item['id'] : null;
                unset($item['id']);

                if ($id !== null) {
                    unset($item['receipt_required']);
                    $existingItems->get($id)->forceFill($item)->save();
                } else {
                    $lockedClaim->items()->create($item);
                }
            }

            $removedItems = $existingItems->except($submittedIds->all());
            $files = $this->deleteItemAttachments($removedItems);
            $removedItems->each->delete();
            $lockedClaim->forceFill([
                'department_id' => $departmentId,
                'title' => trim((string) ($attributes['title'] ?? '')),
                'description' => trim((string) ($attributes['description'] ?? '')),
                'total_amount' => $total->decimal(),
                'lock_version' => $lockedClaim->lock_version + 1,
            ])->save();
            $lockedClaim->load('items');
            $this->auditService->logUpdated($lockedClaim, $user, $oldValues, $this->auditValues($lockedClaim));

            return [$lockedClaim->load(['employee', 'department', 'items.category']), $files];
        }, 5);

        $this->deleteFiles($files);

        return $claim;
    }

    /** @return array<string, mixed> */
    private function auditValues(ExpenseClaim $expenseClaim): array
    {
        return [
            ...$expenseClaim->only(['department_id', 'title', 'total_amount']),
            'items' => $expenseClaim->items->map->only([
                'category_id', 'expense_date', 'amount', 'tax_amount',
            ])->values()->all(),
        ];
    }

    public function submit(ExpenseClaim $expenseClaim, User $user): ExpenseClaim
    {
        return DB::transaction(function () use ($expenseClaim, $user): ExpenseClaim {
            $claim = ExpenseClaim::query()->whereKey($expenseClaim->id)->lockForUpdate()->firstOrFail();
            $employee = User::query()->whereKey($claim->employee_id)->lockForUpdate()->firstOrFail();
            Gate::forUser($employee)->authorize('submit', $claim);

            if ($employee->id !== $user->id) {
                throw new AuthorizationException;
            }

            $departmentId = $this->validateEmployeeDepartment($employee, true);
            $items = ExpenseItem::query()
                ->where('expense_claim_id', $claim->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $items->load('category');

            foreach ($items as $item) {
                $item->setRelation('attachments', $item->attachments()->lockForUpdate()->get());
            }
            $this->validatePersistedClaim($claim, $items);
            [$normalizedItems, $total] = $this->normalizeItems($items->map->only([
                'category_id', 'expense_date', 'merchant', 'description', 'amount', 'tax_amount', 'receipt_required',
            ])->all(), preserveReceiptRequirement: true);
            $this->validateCategories($normalizedItems, true);

            foreach ($items as $index => $item) {
                $item->forceFill($normalizedItems[$index])->save();
            }

            $routingItem = $this->routingItem($items);
            $claim->forceFill([
                'department_id' => $departmentId,
                'total_amount' => $total->decimal(),
            ])->save();
            $context = WorkflowContext::fromValues(
                WorkflowModuleType::ExpenseClaim,
                $employee->id,
                $departmentId,
                $routingItem->category_id,
                $total->decimal(),
                $claim->currency,
            );

            try {
                $resolution = $this->workflowResolver->resolve($context);
                $this->workflowEngine->start($claim, $resolution);
            } catch (WorkflowResolutionException|ApprovalRuntimeException $exception) {
                throw ValidationException::withMessages(['workflow' => $exception->getMessage()]);
            }

            $claim->forceFill([
                'status' => ExpenseClaimStatus::InApproval,
                'submitted_at' => now(),
                'lock_version' => $claim->lock_version + 1,
            ])->save();

            return $claim->load(['items.attachments', 'approvalInstances.steps.assignments', 'approvalInstances.actions']);
        }, 5);
    }

    public function resubmit(ExpenseClaim $expenseClaim, User $user, ?string $comment = null): ExpenseClaim
    {
        return DB::transaction(function () use ($expenseClaim, $user, $comment): ExpenseClaim {
            $claim = ExpenseClaim::query()->whereKey($expenseClaim->id)->lockForUpdate()->firstOrFail();
            $employee = User::query()->whereKey($claim->employee_id)->lockForUpdate()->firstOrFail();
            Gate::forUser($employee)->authorize('resubmit', $claim);

            if ($employee->id !== $user->id) {
                throw new AuthorizationException;
            }

            $departmentId = $this->validateEmployeeDepartment($employee, true);
            $items = ExpenseItem::query()
                ->where('expense_claim_id', $claim->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $items->load('category');

            foreach ($items as $item) {
                $item->setRelation('attachments', $item->attachments()->lockForUpdate()->get());
            }

            $this->validatePersistedClaim($claim, $items);
            [$normalizedItems, $total] = $this->normalizeItems($items->map->only([
                'category_id', 'expense_date', 'merchant', 'description', 'amount', 'tax_amount', 'receipt_required',
            ])->all(), preserveReceiptRequirement: true);
            $this->validateCategories($normalizedItems, true);

            foreach ($items as $index => $item) {
                $item->forceFill($normalizedItems[$index])->save();
            }

            $routingItem = $this->routingItem($items);
            $claim->forceFill([
                'department_id' => $departmentId,
                'total_amount' => $total->decimal(),
            ])->save();
            $context = WorkflowContext::fromValues(
                WorkflowModuleType::ExpenseClaim,
                $employee->id,
                $departmentId,
                $routingItem->category_id,
                $total->decimal(),
                $claim->currency,
            );
            $this->approvalService->resubmit($claim, $user, $context, $comment);

            return $claim->load(['items.attachments', 'approvalInstances.steps.assignments', 'approvalInstances.actions']);
        }, 5);
    }

    public function deleteDraft(ExpenseClaim $expenseClaim, User $user): void
    {
        $files = DB::transaction(function () use ($expenseClaim, $user): array {
            $claim = ExpenseClaim::query()->whereKey($expenseClaim->id)->lockForUpdate()->firstOrFail();
            $employee = User::query()->whereKey($claim->employee_id)->lockForUpdate()->firstOrFail();

            if ($employee->id !== $user->id) {
                throw new AuthorizationException;
            }

            Gate::forUser($employee)->authorize('delete', $claim);

            if ($claim->approvalInstances()->exists()) {
                throw new AuthorizationException('Submitted expense claims cannot be deleted.');
            }

            $items = ExpenseItem::query()->where('expense_claim_id', $claim->id)->lockForUpdate()->get();
            $files = $this->attachmentFiles($claim->attachments()->get(['id', 'disk', 'path']))
                ->concat($this->deleteItemAttachments($items))
                ->all();
            $claim->attachments()->delete();
            $items->each->delete();
            $claim->delete();

            return $files;
        }, 5);

        $this->deleteFiles($files);
    }

    /** @param array<string, mixed> $attributes */
    private function createDraft(array $attributes, User $employee): ExpenseClaim
    {
        $departmentId = $this->validateEmployeeDepartment($employee, true);
        [$items, $total] = $this->normalizeItems($attributes['items'] ?? []);
        $this->validateCategories($items, true);

        $claim = ExpenseClaim::forceCreate([
            'claim_no' => $this->referenceNumbers->next(ReferenceType::ExpenseClaim),
            'employee_id' => $employee->id,
            'department_id' => $departmentId,
            'title' => trim((string) ($attributes['title'] ?? '')),
            'description' => trim((string) ($attributes['description'] ?? '')),
            'currency' => 'MYR',
            'total_amount' => $total->decimal(),
            'status' => ExpenseClaimStatus::Draft,
            'lock_version' => 1,
        ]);
        $claim->items()->createMany($items);

        return $claim->load(['employee', 'department', 'items.category']);
    }

    private function createReceiptAttachment(DocumentIntake $document, ExpenseItem $item, User $employee): Attachment
    {
        $attachment = new Attachment([
            'original_name' => basename($document->original_name),
            'stored_name' => basename($document->path),
            'disk' => $document->disk,
            'path' => $document->path,
            'mime_type' => $document->mime_type,
            'size' => $document->size,
            'uploaded_by' => $employee->id,
        ]);
        $attachment->attachable()->associate($item);
        $attachment->save();

        return $attachment;
    }

    private function validatePrivateReceiptSource(DocumentIntake $document): void
    {
        $this->validatePrivateReceiptMetadata($document);

        if (! Storage::disk($document->disk)->exists($document->path)) {
            throw ValidationException::withMessages([
                'verification' => 'An original receipt is unavailable. Restore it before verification.',
            ]);
        }
    }

    private function validatePrivateReceiptMetadata(DocumentIntake $document): void
    {
        if ($document->disk === ''
            || $document->disk === 'public'
            || config("filesystems.disks.{$document->disk}.visibility") === 'public'
            || ! Str::startsWith($document->path, 'document-intakes/')
            || Str::contains($document->path, ['../', '..\\'])) {
            throw new LogicException('Receipt intake sources must remain on private document storage.');
        }
    }

    private function validateEmployeeDepartment(User $employee, bool $lock = false): int
    {
        $departmentId = (int) $employee->department_id;
        $department = DB::table('departments')->where('id', $departmentId);

        if ($lock) {
            $department->lockForUpdate();
        }

        if ($departmentId < 1 || $department->where('status', MasterDataStatus::Active->value)->first(['id']) === null) {
            throw ValidationException::withMessages(['department' => 'Your user profile must have an active department.']);
        }

        return $departmentId;
    }

    /**
     * @param  iterable<array<string, mixed>>  $rawItems
     * @return array{list<array<string, int|string|null>>, Money}
     */
    private function normalizeItems(
        iterable $rawItems,
        bool $includeIds = false,
        bool $preserveReceiptRequirement = false,
    ): array {
        try {
            $items = [];
            $amounts = [];

            foreach ($rawItems as $rawItem) {
                $amount = Money::of((string) ($rawItem['amount'] ?? ''));
                $tax = Money::of((string) ($rawItem['tax_amount'] ?? ''));

                if ($amount->compare(0) <= 0 || $tax->compare(0) < 0) {
                    throw new InvalidArgumentException;
                }

                $item = [
                    'category_id' => (int) ($rawItem['category_id'] ?? 0),
                    'expense_date' => $rawItem['expense_date'] instanceof \DateTimeInterface
                        ? $rawItem['expense_date']->format('Y-m-d')
                        : (string) ($rawItem['expense_date'] ?? ''),
                    'merchant' => trim((string) ($rawItem['merchant'] ?? '')) ?: null,
                    'description' => trim((string) ($rawItem['description'] ?? '')),
                    'amount' => $amount->decimal(),
                    'tax_amount' => $tax->decimal(),
                    'receipt_required' => $preserveReceiptRequirement
                        ? (bool) ($rawItem['receipt_required'] ?? true)
                        : true,
                ];

                if ($includeIds && isset($rawItem['id'])) {
                    $item['id'] = (int) $rawItem['id'];
                }

                if ($item['category_id'] < 1 || $item['expense_date'] === '' || $item['description'] === '') {
                    throw new InvalidArgumentException;
                }

                $items[] = $item;
                $amounts[] = $amount;
            }

            if ($items === []) {
                throw new InvalidArgumentException;
            }

            return [$items, Money::sum($amounts)];
        } catch (InvalidArgumentException|OverflowException) {
            throw ValidationException::withMessages([
                'items' => 'The expense item details or amounts are invalid or exceed the supported limit.',
            ]);
        }
    }

    /** @param list<array<string, int|string|null>> $items */
    private function validateCategories(array $items, bool $lock = false): void
    {
        $categoryIds = collect($items)->pluck('category_id')->unique()->values();
        $categories = DB::table('spend_categories')->whereIn('id', $categoryIds);

        if ($lock) {
            $categories->lockForUpdate();
        }

        $activeCategoryIds = $categories
            ->where('status', MasterDataStatus::Active->value)
            ->pluck('id');

        if ($activeCategoryIds->count() !== $categoryIds->count()) {
            throw ValidationException::withMessages(['items' => 'Every expense item must use an active spend category.']);
        }
    }

    /** @param Collection<int, ExpenseItem> $items */
    private function validatePersistedClaim(ExpenseClaim $claim, Collection $items): void
    {
        if (trim($claim->title) === '' || trim($claim->description) === '' || $items->isEmpty()) {
            throw ValidationException::withMessages(['expense_claim' => 'Complete the expense claim before submitting.']);
        }

        foreach ($items as $item) {
            if ($item->expense_date === null || $item->expense_date->isFuture()) {
                throw ValidationException::withMessages(['items' => 'Expense dates cannot be in the future.']);
            }

            if ($item->receipt_required && $item->attachments->isEmpty()) {
                throw ValidationException::withMessages([
                    'receipt' => "Attach a receipt for expense item {$item->id} before submitting.",
                ]);
            }
        }
    }

    /** @param Collection<int, ExpenseItem> $items */
    private function routingItem(Collection $items): ExpenseItem
    {
        /** @var ExpenseItem $selected */
        $selected = $items->first();
        $highestAmount = Money::of($selected->amount);

        foreach ($items->skip(1) as $item) {
            $amount = Money::of($item->amount);

            if ($amount->isGreaterThan($highestAmount)) {
                $selected = $item;
                $highestAmount = $amount;
            }
        }

        return $selected;
    }

    /** @param Collection<int, ExpenseItem> $items */
    private function deleteItemAttachments(Collection $items): Collection
    {
        $files = collect();

        foreach ($items as $item) {
            $attachments = $item->attachments()->get(['id', 'disk', 'path']);
            $files = $files->concat($this->attachmentFiles($attachments));
            $item->attachments()->delete();
        }

        return $files;
    }

    private function attachmentFiles(Collection $attachments): Collection
    {
        $intakeSourceAttachmentIds = DocumentIntake::query()
            ->whereIn('expense_item_attachment_id', $attachments->pluck('id'))
            ->pluck('expense_item_attachment_id');

        return $attachments
            ->whereNotIn('id', $intakeSourceAttachmentIds)
            ->map(fn ($attachment): array => ['disk' => $attachment->disk, 'path' => $attachment->path]);
    }

    /** @param iterable<array{disk: string, path: string}> $files */
    private function deleteFiles(iterable $files): void
    {
        foreach ($files as $file) {
            Storage::disk($file['disk'])->delete($file['path']);
        }
    }
}
