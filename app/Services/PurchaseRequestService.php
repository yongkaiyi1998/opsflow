<?php

namespace App\Services;

use App\Exceptions\ApprovalRuntimeException;
use App\Exceptions\WorkflowResolutionException;
use App\MasterDataStatus;
use App\Models\PurchaseRequest;
use App\Models\User;
use App\PurchaseRequestStatus;
use App\ReferenceType;
use App\Support\Money;
use App\UserStatus;
use App\WorkflowContext;
use App\WorkflowModuleType;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use OverflowException;

class PurchaseRequestService
{
    public function __construct(
        private readonly ReferenceNumberGenerator $referenceNumbers,
        private readonly WorkflowResolver $workflowResolver,
        private readonly WorkflowEngine $workflowEngine,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function create(array $attributes, User $requester): PurchaseRequest
    {
        Gate::forUser($requester)->authorize('create', PurchaseRequest::class);

        return DB::transaction(function () use ($attributes, $requester): PurchaseRequest {
            $lockedRequester = User::query()->whereKey($requester->id)->lockForUpdate()->firstOrFail();
            $departmentId = $this->validDepartmentId($lockedRequester);
            [$categoryId, $vendorId] = $this->validateMasterData($attributes);
            [$items, $subtotal, $tax, $total] = $this->calculate($attributes);

            $purchaseRequest = PurchaseRequest::forceCreate([
                'request_no' => $this->referenceNumbers->next(ReferenceType::PurchaseRequest),
                'requester_id' => $lockedRequester->id,
                'department_id' => $departmentId,
                'vendor_id' => $vendorId,
                'category_id' => $categoryId,
                'title' => trim((string) $attributes['title']),
                'description' => trim((string) $attributes['description']),
                'currency' => 'MYR',
                'subtotal' => $subtotal->decimal(),
                'tax_amount' => $tax->decimal(),
                'total_amount' => $total->decimal(),
                'needed_by_date' => $attributes['needed_by_date'] ?? null,
                'status' => PurchaseRequestStatus::Draft,
                'lock_version' => 1,
            ]);
            $purchaseRequest->items()->createMany($items);

            return $purchaseRequest->load(['items', 'requester', 'department', 'category', 'vendor']);
        }, 5);
    }

    /** @param array<string, mixed> $attributes */
    public function update(PurchaseRequest $purchaseRequest, array $attributes, User $user): PurchaseRequest
    {
        return DB::transaction(function () use ($purchaseRequest, $attributes, $user): PurchaseRequest {
            $lockedRequest = PurchaseRequest::query()->whereKey($purchaseRequest->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($user)->authorize('update', $lockedRequest);

            if ($lockedRequest->lock_version !== (int) $attributes['lock_version']) {
                throw ValidationException::withMessages([
                    'lock_version' => 'This purchase request changed while you were editing it. Reload and try again.',
                ]);
            }

            $lockedRequester = User::query()->whereKey($lockedRequest->requester_id)->lockForUpdate()->firstOrFail();
            $departmentId = $this->validDepartmentId($lockedRequester);
            [$categoryId, $vendorId] = $this->validateMasterData($attributes);
            [$items, $subtotal, $tax, $total] = $this->calculate($attributes);

            $lockedRequest->forceFill([
                'department_id' => $departmentId,
                'vendor_id' => $vendorId,
                'category_id' => $categoryId,
                'title' => trim((string) $attributes['title']),
                'description' => trim((string) $attributes['description']),
                'subtotal' => $subtotal->decimal(),
                'tax_amount' => $tax->decimal(),
                'total_amount' => $total->decimal(),
                'needed_by_date' => $attributes['needed_by_date'] ?? null,
                'lock_version' => $lockedRequest->lock_version + 1,
            ])->save();
            $lockedRequest->items()->delete();
            $lockedRequest->items()->createMany($items);

            return $lockedRequest->load(['items', 'requester', 'department', 'category', 'vendor']);
        }, 5);
    }

    public function submit(PurchaseRequest $purchaseRequest, User $user): PurchaseRequest
    {
        return DB::transaction(function () use ($purchaseRequest, $user): PurchaseRequest {
            $lockedRequest = PurchaseRequest::query()->whereKey($purchaseRequest->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($user)->authorize('submit', $lockedRequest);
            $lockedRequest->load('items');
            $requester = User::query()->whereKey($lockedRequest->requester_id)->lockForUpdate()->firstOrFail();
            $departmentId = $this->validDepartmentId($requester);
            $this->validatePersistedRequest($lockedRequest);
            [$items, $subtotal, $tax, $total] = $this->calculate([
                'items' => $lockedRequest->items->map->only(['description', 'quantity', 'unit_price'])->all(),
                'tax_amount' => $lockedRequest->tax_amount,
            ]);

            foreach ($lockedRequest->items as $index => $item) {
                $item->forceFill(['subtotal' => $items[$index]['subtotal']])->save();
            }

            $lockedRequest->forceFill([
                'department_id' => $departmentId,
                'subtotal' => $subtotal->decimal(),
                'tax_amount' => $tax->decimal(),
                'total_amount' => $total->decimal(),
            ])->save();
            $context = WorkflowContext::fromValues(
                WorkflowModuleType::PurchaseRequest,
                $requester->id,
                $departmentId,
                $lockedRequest->category_id,
                $total->decimal(),
                $lockedRequest->currency,
            );
            try {
                $resolution = $this->workflowResolver->resolve($context);
                $this->workflowEngine->start($lockedRequest, $resolution);
            } catch (WorkflowResolutionException|ApprovalRuntimeException $exception) {
                throw ValidationException::withMessages([
                    'workflow' => $exception->getMessage(),
                ]);
            }
            $lockedRequest->forceFill([
                'status' => PurchaseRequestStatus::InApproval,
                'submitted_at' => now(),
                'lock_version' => $lockedRequest->lock_version + 1,
            ])->save();

            return $lockedRequest->load(['items', 'approvalInstances.steps.assignments', 'approvalInstances.actions']);
        }, 5);
    }

    public function deleteDraft(PurchaseRequest $purchaseRequest, User $user): void
    {
        $files = DB::transaction(function () use ($purchaseRequest, $user): array {
            $lockedRequest = PurchaseRequest::query()->whereKey($purchaseRequest->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($user)->authorize('delete', $lockedRequest);

            if ($lockedRequest->approvalInstances()->exists()) {
                throw new AuthorizationException('Submitted purchase requests cannot be deleted.');
            }

            $files = $lockedRequest->attachments()->get(['id', 'disk', 'path'])
                ->map(fn ($attachment): array => ['disk' => $attachment->disk, 'path' => $attachment->path])
                ->all();
            $lockedRequest->attachments()->delete();
            $lockedRequest->items()->delete();
            $lockedRequest->delete();

            return $files;
        }, 5);

        foreach ($files as $file) {
            Storage::disk($file['disk'])->delete($file['path']);
        }
    }

    private function validDepartmentId(User $requester): int
    {
        if ($requester->status !== UserStatus::Active || $requester->department_id === null) {
            throw ValidationException::withMessages(['requester' => 'An active requester with an active department is required.']);
        }

        $active = DB::table('departments')
            ->where('id', $requester->department_id)
            ->where('status', MasterDataStatus::Active->value)
            ->exists();

        if (! $active) {
            throw ValidationException::withMessages(['requester' => 'An active requester with an active department is required.']);
        }

        return $requester->department_id;
    }

    /** @param array<string, mixed> $attributes
     * @return array{int, ?int}
     */
    private function validateMasterData(array $attributes): array
    {
        $categoryId = (int) $attributes['category_id'];
        $vendorId = isset($attributes['vendor_id']) && $attributes['vendor_id'] !== '' ? (int) $attributes['vendor_id'] : null;

        if (! DB::table('spend_categories')->where('id', $categoryId)->where('status', MasterDataStatus::Active->value)->exists()) {
            throw ValidationException::withMessages(['category_id' => 'Select an active spend category.']);
        }

        if ($vendorId !== null && ! DB::table('vendors')->where('id', $vendorId)->where('status', MasterDataStatus::Active->value)->exists()) {
            throw ValidationException::withMessages(['vendor_id' => 'Select an active vendor.']);
        }

        return [$categoryId, $vendorId];
    }

    /** @param array<string, mixed> $attributes
     * @return array{list<array{description: string, quantity: int, unit_price: string, subtotal: string}>, Money, Money, Money}
     */
    private function calculate(array $attributes): array
    {
        try {
            $items = [];
            $subtotals = [];

            foreach ($attributes['items'] as $item) {
                $rawQuantity = $item['quantity'];

                if ((! is_int($rawQuantity) && ! is_string($rawQuantity))
                    || preg_match('/^\d+$/', (string) $rawQuantity) !== 1) {
                    throw new InvalidArgumentException;
                }

                $unitPrice = Money::of((string) $item['unit_price']);
                $quantity = (int) $rawQuantity;

                if ($quantity < 1 || $quantity > 1000000 || $unitPrice->compare(0) <= 0) {
                    throw new InvalidArgumentException;
                }

                $itemSubtotal = $unitPrice->multiply($quantity);
                $subtotals[] = $itemSubtotal;
                $items[] = [
                    'description' => trim((string) $item['description']),
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice->decimal(),
                    'subtotal' => $itemSubtotal->decimal(),
                ];
            }

            if ($items === [] || collect($items)->contains(fn (array $item): bool => $item['description'] === '')) {
                throw new InvalidArgumentException;
            }

            $subtotal = Money::sum($subtotals);
            $tax = Money::of((string) $attributes['tax_amount']);
            if ($tax->compare(0) < 0) {
                throw new InvalidArgumentException;
            }

            return [$items, $subtotal, $tax, $subtotal->add($tax)];
        } catch (InvalidArgumentException|OverflowException) {
            throw ValidationException::withMessages([
                'items' => 'The purchase request amounts or quantities are invalid or exceed the supported limit.',
            ]);
        }
    }

    private function validatePersistedRequest(PurchaseRequest $purchaseRequest): void
    {
        if (trim($purchaseRequest->title) === '' || trim($purchaseRequest->description) === '') {
            throw ValidationException::withMessages(['purchase_request' => 'Complete the purchase request before submitting.']);
        }

        $this->validateMasterData([
            'category_id' => $purchaseRequest->category_id,
            'vendor_id' => $purchaseRequest->vendor_id,
        ]);

        if ($purchaseRequest->needed_by_date?->isBefore(today())) {
            throw ValidationException::withMessages(['needed_by_date' => 'The needed-by date must be today or later.']);
        }
    }
}
