<?php

namespace App\Services;

use App\Exceptions\ApprovalRuntimeException;
use App\Exceptions\WorkflowResolutionException;
use App\MasterDataStatus;
use App\Models\SupplierInvoice;
use App\Models\User;
use App\ReferenceType;
use App\SupplierInvoiceStatus;
use App\Support\Money;
use App\WorkflowContext;
use App\WorkflowModuleType;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use OverflowException;

class SupplierInvoiceService
{
    private const VENDOR_INVOICE_UNIQUE_INDEX = 'supplier_invoices_vendor_invoice_no_unique';

    public function __construct(
        private readonly ReferenceNumberGenerator $referenceNumbers,
        private readonly WorkflowResolver $workflowResolver,
        private readonly WorkflowEngine $workflowEngine,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function create(array $attributes, User $user): SupplierInvoice
    {
        Gate::forUser($user)->authorize('create', SupplierInvoice::class);

        try {
            return DB::transaction(function () use ($attributes, $user): SupplierInvoice {
                $lockedUser = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
                Gate::forUser($lockedUser)->authorize('create', SupplierInvoice::class);
                [$vendorId, $departmentId, $categoryId] = $this->validateMasterData($attributes);
                $invoiceNumber = $this->normalizeInvoiceNumber($attributes['invoice_no'] ?? null);
                $this->ensureUniqueVendorInvoice($vendorId, $invoiceNumber);
                [$items, $subtotal, $tax, $total] = $this->calculate($attributes);

                $invoice = SupplierInvoice::forceCreate([
                    'internal_no' => $this->referenceNumbers->next(ReferenceType::SupplierInvoice),
                    'invoice_no' => $invoiceNumber,
                    'vendor_id' => $vendorId,
                    'department_id' => $departmentId,
                    'category_id' => $categoryId,
                    'submitted_by' => $lockedUser->id,
                    'invoice_date' => $attributes['invoice_date'],
                    'due_date' => $attributes['due_date'] ?? null,
                    'currency' => 'MYR',
                    'subtotal' => $subtotal->decimal(),
                    'tax_amount' => $tax->decimal(),
                    'total_amount' => $total->decimal(),
                    'description' => trim((string) $attributes['description']),
                    'status' => SupplierInvoiceStatus::Draft,
                    'lock_version' => 1,
                ]);
                $invoice->items()->createMany($items);

                return $invoice->load(['items', 'vendor', 'department', 'category', 'submittedBy']);
            }, 5);
        } catch (QueryException $exception) {
            $this->throwIfVendorInvoiceConflict($exception);

            throw $exception;
        }
    }

    /** @param array<string, mixed> $attributes */
    public function update(SupplierInvoice $supplierInvoice, array $attributes, User $user): SupplierInvoice
    {
        try {
            return DB::transaction(function () use ($supplierInvoice, $attributes, $user): SupplierInvoice {
                $lockedInvoice = SupplierInvoice::query()->whereKey($supplierInvoice->id)->lockForUpdate()->firstOrFail();
                Gate::forUser($user)->authorize('update', $lockedInvoice);

                if ($lockedInvoice->lock_version !== (int) $attributes['lock_version']) {
                    throw ValidationException::withMessages([
                        'lock_version' => 'This supplier invoice changed while you were editing it. Reload and try again.',
                    ]);
                }

                [$vendorId, $departmentId, $categoryId] = $this->validateMasterData($attributes);
                $invoiceNumber = $this->normalizeInvoiceNumber($attributes['invoice_no'] ?? null);
                $this->ensureUniqueVendorInvoice($vendorId, $invoiceNumber, $lockedInvoice->id);
                [$items, $subtotal, $tax, $total] = $this->calculate($attributes);

                $lockedInvoice->forceFill([
                    'invoice_no' => $invoiceNumber,
                    'vendor_id' => $vendorId,
                    'department_id' => $departmentId,
                    'category_id' => $categoryId,
                    'invoice_date' => $attributes['invoice_date'],
                    'due_date' => $attributes['due_date'] ?? null,
                    'subtotal' => $subtotal->decimal(),
                    'tax_amount' => $tax->decimal(),
                    'total_amount' => $total->decimal(),
                    'description' => trim((string) $attributes['description']),
                    'lock_version' => $lockedInvoice->lock_version + 1,
                ])->save();
                $lockedInvoice->items()->delete();
                $lockedInvoice->items()->createMany($items);

                return $lockedInvoice->load(['items', 'vendor', 'department', 'category', 'submittedBy']);
            }, 5);
        } catch (QueryException $exception) {
            $this->throwIfVendorInvoiceConflict($exception);

            throw $exception;
        }
    }

    public function submit(SupplierInvoice $supplierInvoice, User $user): SupplierInvoice
    {
        return DB::transaction(function () use ($supplierInvoice, $user): SupplierInvoice {
            $lockedInvoice = SupplierInvoice::query()->whereKey($supplierInvoice->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($user)->authorize('submit', $lockedInvoice);
            $lockedInvoice->load('items');
            $submittedBy = User::query()->whereKey($lockedInvoice->submitted_by)->lockForUpdate()->firstOrFail();
            $this->validatePersistedInvoice($lockedInvoice);
            [$items, $subtotal, $tax, $total] = $this->calculate([
                'items' => $lockedInvoice->items->map->only(['description', 'quantity', 'unit_price'])->all(),
                'tax_amount' => $lockedInvoice->tax_amount,
            ]);

            foreach ($lockedInvoice->items as $index => $item) {
                $item->forceFill(['quantity' => $items[$index]['quantity'], 'subtotal' => $items[$index]['subtotal']])->save();
            }

            $lockedInvoice->forceFill([
                'subtotal' => $subtotal->decimal(),
                'tax_amount' => $tax->decimal(),
                'total_amount' => $total->decimal(),
            ])->save();
            $context = WorkflowContext::fromValues(
                WorkflowModuleType::SupplierInvoice,
                $submittedBy->id,
                $lockedInvoice->department_id,
                $lockedInvoice->category_id,
                $total->decimal(),
                $lockedInvoice->currency,
            );

            try {
                $resolution = $this->workflowResolver->resolve($context);
                $this->workflowEngine->start($lockedInvoice, $resolution);
            } catch (WorkflowResolutionException|ApprovalRuntimeException $exception) {
                throw ValidationException::withMessages(['workflow' => $exception->getMessage()]);
            }

            $lockedInvoice->forceFill([
                'status' => SupplierInvoiceStatus::InApproval,
                'submitted_at' => now(),
                'lock_version' => $lockedInvoice->lock_version + 1,
            ])->save();

            return $lockedInvoice->load(['items', 'approvalInstances.steps.assignments', 'approvalInstances.actions']);
        }, 5);
    }

    public function deleteDraft(SupplierInvoice $supplierInvoice, User $user): void
    {
        $files = DB::transaction(function () use ($supplierInvoice, $user): array {
            $lockedInvoice = SupplierInvoice::query()->whereKey($supplierInvoice->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($user)->authorize('delete', $lockedInvoice);

            if ($lockedInvoice->approvalInstances()->exists()) {
                throw new AuthorizationException('Submitted supplier invoices cannot be deleted.');
            }

            $files = $lockedInvoice->attachments()->get(['id', 'disk', 'path'])
                ->map(fn ($attachment): array => ['disk' => $attachment->disk, 'path' => $attachment->path])
                ->all();
            $lockedInvoice->attachments()->delete();
            $lockedInvoice->items()->delete();
            $lockedInvoice->delete();

            return $files;
        }, 5);

        foreach ($files as $file) {
            Storage::disk($file['disk'])->delete($file['path']);
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{int, int, int}
     */
    private function validateMasterData(array $attributes): array
    {
        $models = [
            'vendor_id' => ['table' => 'vendors', 'message' => 'Select an active vendor.'],
            'department_id' => ['table' => 'departments', 'message' => 'Select an active department.'],
            'category_id' => ['table' => 'spend_categories', 'message' => 'Select an active spend category.'],
        ];
        $ids = [];

        foreach ($models as $field => $configuration) {
            $id = (int) ($attributes[$field] ?? 0);

            if ($id < 1 || ! DB::table($configuration['table'])->where('id', $id)->where('status', MasterDataStatus::Active->value)->exists()) {
                throw ValidationException::withMessages([$field => $configuration['message']]);
            }

            $ids[] = $id;
        }

        return $ids;
    }

    private function normalizeInvoiceNumber(mixed $invoiceNumber): string
    {
        $normalized = Str::trim(is_string($invoiceNumber) ? $invoiceNumber : '');

        if ($normalized === '' || mb_strlen($normalized) > 100) {
            throw ValidationException::withMessages(['invoice_no' => 'Enter a valid supplier invoice number.']);
        }

        return $normalized;
    }

    private function ensureUniqueVendorInvoice(int $vendorId, string $invoiceNumber, ?int $ignoreId = null): void
    {
        $duplicate = SupplierInvoice::query()
            ->where('vendor_id', $vendorId)
            ->where('invoice_no', $invoiceNumber)
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages([
                'invoice_no' => 'This supplier invoice number already exists for the selected vendor.',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{list<array{description: string, quantity: string, unit_price: string, subtotal: string}>, Money, Money, Money}
     */
    private function calculate(array $attributes): array
    {
        try {
            $items = [];
            $subtotals = [];

            foreach ($attributes['items'] as $item) {
                $quantity = $this->normalizeQuantity($item['quantity'] ?? null);
                $unitPrice = Money::of((string) ($item['unit_price'] ?? ''));

                if ($unitPrice->compare(0) <= 0) {
                    throw new InvalidArgumentException;
                }

                $itemSubtotal = $unitPrice->multiply($quantity);
                $subtotals[] = $itemSubtotal;
                $items[] = [
                    'description' => trim((string) ($item['description'] ?? '')),
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice->decimal(),
                    'subtotal' => $itemSubtotal->decimal(),
                ];
            }

            if ($items === [] || collect($items)->contains(fn (array $item): bool => $item['description'] === '')) {
                throw new InvalidArgumentException;
            }

            $subtotal = Money::sum($subtotals);
            $tax = Money::of((string) ($attributes['tax_amount'] ?? ''));

            if ($tax->compare(0) < 0) {
                throw new InvalidArgumentException;
            }

            return [$items, $subtotal, $tax, $subtotal->add($tax)];
        } catch (InvalidArgumentException|OverflowException) {
            throw ValidationException::withMessages([
                'items' => 'The supplier invoice amounts or quantities are invalid or exceed the supported limit.',
            ]);
        }
    }

    private function normalizeQuantity(mixed $quantity): string
    {
        if ((! is_string($quantity) && ! is_int($quantity))
            || preg_match('/^(?<whole>\d{1,11})(?:\.(?<fraction>\d{1,4}))?$/', (string) $quantity, $matches) !== 1) {
            throw new InvalidArgumentException;
        }

        $whole = ltrim($matches['whole'], '0');
        $whole = $whole === '' ? '0' : $whole;
        $fraction = str_pad($matches['fraction'] ?? '', 4, '0');

        if ($whole === '0' && $fraction === '0000') {
            throw new InvalidArgumentException;
        }

        return $whole.'.'.$fraction;
    }

    private function validatePersistedInvoice(SupplierInvoice $invoice): void
    {
        $invoiceNumber = $this->normalizeInvoiceNumber($invoice->invoice_no);
        $this->validateMasterData([
            'vendor_id' => $invoice->vendor_id,
            'department_id' => $invoice->department_id,
            'category_id' => $invoice->category_id,
        ]);
        $this->ensureUniqueVendorInvoice($invoice->vendor_id, $invoiceNumber, $invoice->id);

        if (trim($invoice->description) === '' || $invoice->invoice_date === null
            || ($invoice->due_date !== null && $invoice->due_date->isBefore($invoice->invoice_date))) {
            throw ValidationException::withMessages(['supplier_invoice' => 'Complete the supplier invoice before submitting.']);
        }

        if (! $invoice->attachments()->exists()) {
            throw ValidationException::withMessages([
                'attachment' => 'Attach the supplier invoice document before submitting.',
            ]);
        }
    }

    private function throwIfVendorInvoiceConflict(QueryException $exception): void
    {
        $isIntegrityViolation = (string) ($exception->errorInfo[0] ?? '') === '23000';
        $identifiesVendorInvoiceConstraint = str_contains($exception->getMessage(), self::VENDOR_INVOICE_UNIQUE_INDEX)
            || str_contains($exception->getMessage(), 'supplier_invoices.vendor_id, supplier_invoices.invoice_no');

        if ($isIntegrityViolation && $identifiesVendorInvoiceConstraint) {
            throw ValidationException::withMessages([
                'invoice_no' => 'This supplier invoice number already exists for the selected vendor.',
            ]);
        }
    }
}
