@extends('layouts.app')
@section('title', 'Approval review')
@section('content')
@php
    $context = $instance->context();
    $reference = match (true) {
        $business instanceof App\Models\PurchaseRequest => $business->request_no,
        $business instanceof App\Models\SupplierInvoice => $business->internal_no,
        $business instanceof App\Models\ExpenseClaim => $business->claim_no,
    };
    $requester = match (true) {
        $business instanceof App\Models\PurchaseRequest => $business->requester,
        $business instanceof App\Models\SupplierInvoice => $business->submittedBy,
        $business instanceof App\Models\ExpenseClaim => $business->employee,
    };
    $businessRoute = match (true) {
        $business instanceof App\Models\PurchaseRequest => route('purchase-requests.show', $business),
        $business instanceof App\Models\SupplierInvoice => route('supplier-invoices.show', $business),
        $business instanceof App\Models\ExpenseClaim => route('expense-claims.show', $business),
    };
@endphp
<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4"><div><div class="text-secondary">{{ $context->moduleType->label() }} · {{ $reference }}</div><h1 class="h2 mb-1">Approval review</h1><span class="badge text-bg-primary">{{ $approvalAssignment->step->name }}</span></div><a class="btn btn-outline-secondary" href="{{ $businessRoute }}">Open business record</a></div>
<div class="row g-4">
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm mb-4"><div class="card-body"><h2 class="h5">Business details</h2><dl class="row mb-0"><dt class="col-sm-4">Reference</dt><dd class="col-sm-8">{{ $reference }}</dd><dt class="col-sm-4">Requester</dt><dd class="col-sm-8">{{ $requester->name }}</dd><dt class="col-sm-4">Department</dt><dd class="col-sm-8">{{ $business->department->name }}</dd><dt class="col-sm-4">Amount</dt><dd class="col-sm-8 fw-semibold">{{ $context->amount->format($context->currency) }}</dd>@if ($business instanceof App\Models\PurchaseRequest)<dt class="col-sm-4">Title</dt><dd class="col-sm-8">{{ $business->title }}</dd><dt class="col-sm-4">Category</dt><dd class="col-sm-8">{{ $business->category->name }}</dd><dt class="col-sm-4">Vendor</dt><dd class="col-sm-8">{{ $business->vendor?->name ?? 'Not selected' }}</dd><dt class="col-sm-4">Justification</dt><dd class="col-sm-8">{{ $business->description }}</dd>@elseif ($business instanceof App\Models\SupplierInvoice)<dt class="col-sm-4">Invoice number</dt><dd class="col-sm-8">{{ $business->invoice_no }}</dd><dt class="col-sm-4">Vendor</dt><dd class="col-sm-8">{{ $business->vendor->name }}</dd><dt class="col-sm-4">Description</dt><dd class="col-sm-8">{{ $business->description }}</dd>@else<dt class="col-sm-4">Title</dt><dd class="col-sm-8">{{ $business->title }}</dd><dt class="col-sm-4">Description</dt><dd class="col-sm-8">{{ $business->description }}</dd>@endif</dl></div></div>
        <div class="card border-0 shadow-sm mb-4"><div class="card-body"><h2 class="h5">Items</h2><div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Description</th>@if ($business instanceof App\Models\ExpenseClaim)<th>Category</th><th>Date</th>@else<th class="text-end">Quantity</th>@endif<th class="text-end">Amount</th></tr></thead><tbody>@foreach ($business->items as $item)<tr><td>{{ $item->description }}@if ($business instanceof App\Models\ExpenseClaim && $item->merchant)<div class="small text-secondary">{{ $item->merchant }}</div>@endif</td>@if ($business instanceof App\Models\ExpenseClaim)<td>{{ $item->category->name }}</td><td>{{ $item->expense_date->format('j M Y') }}</td><td class="text-end">{{ App\Support\Money::of($item->amount)->format($business->currency) }}</td>@else<td class="text-end">{{ rtrim(rtrim($item->quantity, '0'), '.') }}</td><td class="text-end">{{ App\Support\Money::of($item->subtotal)->format($business->currency) }}</td>@endif</tr>@endforeach</tbody></table></div></div></div>
        <div class="card border-0 shadow-sm"><div class="card-body"><h2 class="h5">Approval timeline</h2><ol class="list-group list-group-numbered mb-4">@foreach ($instance->steps as $step)<li class="list-group-item d-flex justify-content-between align-items-start"><div class="ms-2 me-auto"><div class="fw-semibold">{{ $step->name }}</div>@forelse ($step->assignments as $assignment)<small class="text-secondary d-block">{{ $assignment->approver->name }} · {{ str($assignment->status->value)->title() }}</small>@empty<small class="text-secondary">Assigned when this step becomes active.</small>@endforelse</div><span class="badge text-bg-secondary">{{ str($step->status->value)->title() }}</span></li>@endforeach</ol>@foreach ($instance->actions as $action)<div class="border-start border-3 ps-3 mb-3"><div><strong>{{ str($action->action->value)->replace('_', ' ')->title() }}</strong>@if ($action->actor) by {{ $action->actor->name }}@endif</div><div class="small text-secondary">{{ $action->created_at->format('j M Y H:i') }}@if ($action->step) · {{ $action->step->name }}@endif</div>@if ($action->comment)<div class="mt-1">{{ $action->comment }}</div>@endif</div>@endforeach</div></div>
    </div>
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm mb-4"><div class="card-body"><h2 class="h5">Private attachments</h2>
            @if ($business instanceof App\Models\ExpenseClaim)
                @foreach ($business->items as $item)
                    <div class="fw-semibold mt-2">{{ $item->description }}</div>
                    @forelse ($item->attachments as $attachment)
                        <a class="d-block py-1" href="{{ route('attachments.download', $attachment) }}">{{ $attachment->original_name }}</a>
                    @empty
                        <div class="small text-secondary">No receipt attached.</div>
                    @endforelse
                @endforeach
            @else
                @forelse ($business->attachments as $attachment)
                    <a class="d-block py-1" href="{{ route('attachments.download', $attachment) }}">{{ $attachment->original_name }}</a>
                @empty
                    <p class="text-secondary mb-0">No attachments.</p>
                @endforelse
            @endif
        </div></div>
        @if ($actionable)<div class="card border-0 shadow-sm"><div class="card-body"><h2 class="h5">Decision</h2><form class="mb-4" method="POST" action="{{ route('approval-assignments.approve', $approvalAssignment) }}">@csrf<label class="form-label" for="approve-comment">Approval comment <span class="text-secondary">(optional)</span></label><textarea class="form-control" id="approve-comment" name="comment" rows="2" maxlength="2000"></textarea><button class="btn btn-success w-100 mt-2" type="submit">Approve</button></form><form class="mb-4" method="POST" action="{{ route('approval-assignments.request-changes', $approvalAssignment) }}">@csrf<label class="form-label" for="changes-comment">Required changes</label><textarea class="form-control" id="changes-comment" name="comment" rows="3" maxlength="2000" required></textarea><button class="btn btn-warning w-100 mt-2" type="submit">Request changes</button></form><form method="POST" action="{{ route('approval-assignments.reject', $approvalAssignment) }}">@csrf<label class="form-label" for="reject-comment">Rejection reason</label><textarea class="form-control" id="reject-comment" name="comment" rows="3" maxlength="2000" required></textarea><button class="btn btn-outline-danger w-100 mt-2" type="submit">Reject</button></form></div></div>@else<div class="alert alert-secondary">This assignment is no longer actionable.</div>@endif
    </div>
</div>
@endsection
