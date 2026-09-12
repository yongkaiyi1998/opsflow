<?php

namespace App\Http\Controllers;

use App\ApprovalAssignmentStatus;
use App\Http\Requests\StorePurchaseRequestRequest;
use App\Http\Requests\UpdatePurchaseRequestRequest;
use App\MasterDataStatus;
use App\Models\PurchaseRequest;
use App\Models\SpendCategory;
use App\Models\Vendor;
use App\PurchaseRequestStatus;
use App\Services\PurchaseRequestService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PurchaseRequestController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', PurchaseRequest::class);
        $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(PurchaseRequestStatus::class)],
        ]);
        $user = $request->user();
        $search = $request->string('search')->trim()->toString();
        $status = $request->string('status')->toString();
        $purchaseRequests = PurchaseRequest::query()
            ->with(['requester', 'department', 'category', 'vendor'])
            ->when(! $user->isAdmin(), fn (Builder $query): Builder => $query->where(
                fn (Builder $query): Builder => $query->where('requester_id', $user->id)
                    ->orWhereHas('approvalInstances.steps.assignments', fn (Builder $query): Builder => $query
                        ->where('approver_id', $user->id)
                        ->where('status', ApprovalAssignmentStatus::Pending->value)),
            ))
            ->when($search, fn (Builder $query): Builder => $query->where(
                fn (Builder $query): Builder => $query->where('request_no', 'like', "%{$search}%")
                    ->orWhere('title', 'like', "%{$search}%")
                    ->orWhereRelation('requester', 'name', 'like', "%{$search}%")
                    ->orWhereRelation('vendor', 'name', 'like', "%{$search}%"),
            ))
            ->when($status, fn (Builder $query): Builder => $query->where('status', $status))
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return view('purchase-requests.index', compact('purchaseRequests', 'search', 'status'));
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(): View
    {
        Gate::authorize('create', PurchaseRequest::class);

        return view('purchase-requests.create', $this->formData());
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StorePurchaseRequestRequest $request, PurchaseRequestService $service): RedirectResponse
    {
        $purchaseRequest = $service->create($request->validated(), $request->user());

        return redirect()->route('purchase-requests.show', $purchaseRequest)->with('success', 'Purchase request created.');
    }

    /**
     * Display the specified resource.
     */
    public function show(PurchaseRequest $purchaseRequest): View
    {
        Gate::authorize('view', $purchaseRequest);
        $purchaseRequest->load([
            'requester', 'department', 'category', 'vendor', 'items', 'attachments.uploadedBy',
            'approvalInstances.workflowVersion', 'approvalInstances.workflowRuleGroup',
            'approvalInstances.steps.assignments.approver', 'approvalInstances.actions.actor', 'approvalInstances.actions.step',
        ]);

        return view('purchase-requests.show', compact('purchaseRequest'));
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(PurchaseRequest $purchaseRequest): View
    {
        Gate::authorize('update', $purchaseRequest);
        $purchaseRequest->load('items');

        return view('purchase-requests.edit', array_merge(
            ['purchaseRequest' => $purchaseRequest],
            $this->formData(),
        ));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdatePurchaseRequestRequest $request, PurchaseRequest $purchaseRequest, PurchaseRequestService $service): RedirectResponse
    {
        $purchaseRequest = $service->update($purchaseRequest, $request->validated(), $request->user());

        return redirect()->route('purchase-requests.show', $purchaseRequest)->with('success', 'Purchase request updated.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, PurchaseRequest $purchaseRequest, PurchaseRequestService $service): RedirectResponse
    {
        $service->deleteDraft($purchaseRequest, $request->user());

        return redirect()->route('purchase-requests.index')->with('success', 'Draft purchase request deleted.');
    }

    /** @return array{categories: Collection<int, SpendCategory>, vendors: Collection<int, Vendor>} */
    private function formData(): array
    {
        return [
            'categories' => SpendCategory::query()->where('status', MasterDataStatus::Active->value)->orderBy('name')->get(),
            'vendors' => Vendor::query()->where('status', MasterDataStatus::Active->value)->orderBy('name')->get(),
        ];
    }
}
