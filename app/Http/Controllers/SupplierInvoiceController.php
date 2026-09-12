<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSupplierInvoiceRequest;
use App\Http\Requests\UpdateSupplierInvoiceRequest;
use App\MasterDataStatus;
use App\Models\Department;
use App\Models\SpendCategory;
use App\Models\SupplierInvoice;
use App\Models\Vendor;
use App\Services\SupplierInvoiceService;
use App\SupplierInvoiceStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SupplierInvoiceController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', SupplierInvoice::class);
        $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(SupplierInvoiceStatus::class)],
            'vendor_id' => ['nullable', 'integer'],
            'department_id' => ['nullable', 'integer'],
            'category_id' => ['nullable', 'integer'],
        ]);
        $search = $request->string('search')->trim()->toString();
        $status = $request->string('status')->toString();
        $vendorId = $request->integer('vendor_id') ?: null;
        $departmentId = $request->integer('department_id') ?: null;
        $categoryId = $request->integer('category_id') ?: null;
        $supplierInvoices = SupplierInvoice::query()
            ->with(['vendor', 'department', 'category', 'submittedBy'])
            ->when($search, fn (Builder $query): Builder => $query->where(
                fn (Builder $query): Builder => $query->where('internal_no', 'like', "%{$search}%")
                    ->orWhere('invoice_no', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhereRelation('vendor', 'name', 'like', "%{$search}%"),
            ))
            ->when($status, fn (Builder $query): Builder => $query->where('status', $status))
            ->when($vendorId, fn (Builder $query): Builder => $query->where('vendor_id', $vendorId))
            ->when($departmentId, fn (Builder $query): Builder => $query->where('department_id', $departmentId))
            ->when($categoryId, fn (Builder $query): Builder => $query->where('category_id', $categoryId))
            ->latest()
            ->latest('id')
            ->paginate(15)
            ->withQueryString();

        return view('supplier-invoices.index', array_merge(
            compact('supplierInvoices', 'search', 'status', 'vendorId', 'departmentId', 'categoryId'),
            $this->formData(),
        ));
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(): View
    {
        Gate::authorize('create', SupplierInvoice::class);

        return view('supplier-invoices.create', $this->formData());
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreSupplierInvoiceRequest $request, SupplierInvoiceService $service): RedirectResponse
    {
        $supplierInvoice = $service->create($request->validated(), $request->user());

        return redirect()->route('supplier-invoices.show', $supplierInvoice)->with('success', 'Supplier invoice created.');
    }

    /**
     * Display the specified resource.
     */
    public function show(SupplierInvoice $supplierInvoice): View
    {
        Gate::authorize('view', $supplierInvoice);
        $supplierInvoice->load([
            'vendor', 'department', 'category', 'submittedBy', 'items', 'attachments.uploadedBy',
            'approvalInstances.workflowVersion', 'approvalInstances.workflowRuleGroup',
            'approvalInstances.steps.assignments.approver', 'approvalInstances.actions.actor', 'approvalInstances.actions.step',
        ]);

        return view('supplier-invoices.show', compact('supplierInvoice'));
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(SupplierInvoice $supplierInvoice): View
    {
        Gate::authorize('update', $supplierInvoice);
        $supplierInvoice->load('items');

        return view('supplier-invoices.edit', array_merge(
            ['supplierInvoice' => $supplierInvoice],
            $this->formData(),
        ));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateSupplierInvoiceRequest $request, SupplierInvoice $supplierInvoice, SupplierInvoiceService $service): RedirectResponse
    {
        $supplierInvoice = $service->update($supplierInvoice, $request->validated(), $request->user());

        return redirect()->route('supplier-invoices.show', $supplierInvoice)->with('success', 'Supplier invoice updated.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, SupplierInvoice $supplierInvoice, SupplierInvoiceService $service): RedirectResponse
    {
        $service->deleteDraft($supplierInvoice, $request->user());

        return redirect()->route('supplier-invoices.index')->with('success', 'Draft supplier invoice deleted.');
    }

    /** @return array{vendors: Collection<int, Vendor>, departments: Collection<int, Department>, categories: Collection<int, SpendCategory>} */
    private function formData(): array
    {
        return [
            'vendors' => Vendor::query()->where('status', MasterDataStatus::Active->value)->orderBy('name')->get(),
            'departments' => Department::query()->where('status', MasterDataStatus::Active->value)->orderBy('name')->get(),
            'categories' => SpendCategory::query()->where('status', MasterDataStatus::Active->value)->orderBy('name')->get(),
        ];
    }
}
