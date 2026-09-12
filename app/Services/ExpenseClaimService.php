<?php

namespace App\Services;

use App\Exceptions\ApprovalRuntimeException;
use App\Exceptions\WorkflowResolutionException;
use App\ExpenseClaimStatus;
use App\MasterDataStatus;
use App\Models\ExpenseClaim;
use App\Models\ExpenseItem;
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
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use OverflowException;

class ExpenseClaimService
{
    public function __construct(
        private readonly ReferenceNumberGenerator $referenceNumbers,
        private readonly WorkflowResolver $workflowResolver,
        private readonly WorkflowEngine $workflowEngine,
        private readonly ApprovalService $approvalService,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function create(array $attributes, User $user): ExpenseClaim
    {
        Gate::forUser($user)->authorize('create', ExpenseClaim::class);

        return DB::transaction(function () use ($attributes, $user): ExpenseClaim {
            $employee = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($employee)->authorize('create', ExpenseClaim::class);
            $departmentId = $this->validateEmployeeDepartment($employee, true);
            [$items, $total] = $this->normalizeItems($attributes['items'] ?? []);
            $this->validateCategories($items);

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

            return [$lockedClaim->load(['employee', 'department', 'items.category']), $files];
        }, 5);

        $this->deleteFiles($files);

        return $claim;
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
            Gate::forUser($user)->authorize('delete', $claim);

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

    private function validateEmployeeDepartment(User $employee, bool $lock = false): int
    {
        $departmentId = (int) $employee->department_id;
        $department = DB::table('departments')->where('id', $departmentId);

        if ($lock) {
            $department->lockForUpdate();
        }

        if ($departmentId < 1 || ! $department->where('status', MasterDataStatus::Active->value)->exists()) {
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

        $activeCount = $categories->where('status', MasterDataStatus::Active->value)->count();

        if ($activeCount !== $categoryIds->count()) {
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
        return $attachments->map(fn ($attachment): array => ['disk' => $attachment->disk, 'path' => $attachment->path]);
    }

    /** @param iterable<array{disk: string, path: string}> $files */
    private function deleteFiles(iterable $files): void
    {
        foreach ($files as $file) {
            Storage::disk($file['disk'])->delete($file['path']);
        }
    }
}
