@extends('layouts.app')
@section('title', 'Supplier invoices')
@section('content')
<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div><h1 class="h2 mb-1">Supplier invoices</h1><p class="text-secondary mb-0">Record and track invoices awaiting internal approval.</p></div>
    <a class="btn btn-primary" href="{{ route('supplier-invoices.create') }}">New invoice</a>
</div>
<form class="row g-2 mb-4" method="GET">
    <div class="col-lg-4"><label class="visually-hidden" for="search">Search</label><input class="form-control" id="search" name="search" value="{{ $search }}" placeholder="Search internal number, invoice, vendor or description"></div>
    <div class="col-lg-2"><label class="visually-hidden" for="status">Status</label><select class="form-select" id="status" name="status"><option value="">All statuses</option>@foreach (App\SupplierInvoiceStatus::cases() as $option)<option value="{{ $option->value }}" @selected($status === $option->value)>{{ str($option->value)->replace('_', ' ')->title() }}</option>@endforeach</select></div>
    <div class="col-lg-2"><label class="visually-hidden" for="vendor_id">Vendor</label><select class="form-select" id="vendor_id" name="vendor_id"><option value="">All vendors</option>@foreach ($vendors as $vendor)<option value="{{ $vendor->id }}" @selected($vendorId === $vendor->id)>{{ $vendor->name }}</option>@endforeach</select></div>
    <div class="col-auto"><button class="btn btn-outline-secondary" type="submit">Filter</button></div>
    @if ($search || $status || $vendorId || $departmentId || $categoryId)<div class="col-auto"><a class="btn btn-link" href="{{ route('supplier-invoices.index') }}">Clear</a></div>@endif
</form>
<div class="card border-0 shadow-sm"><div class="table-responsive"><table class="table table-hover align-middle mb-0">
    <thead class="table-light"><tr><th>Internal no.</th><th>Supplier invoice</th><th>Vendor</th><th>Department</th><th>Invoice date</th><th>Due date</th><th class="text-end">Total</th><th>Status</th><th></th></tr></thead>
    <tbody>@forelse ($supplierInvoices as $invoice)
        <tr><td class="fw-semibold">{{ $invoice->internal_no }}</td><td>{{ $invoice->invoice_no }}</td><td>{{ $invoice->vendor->name }}</td><td>{{ $invoice->department->name }}</td><td>{{ $invoice->invoice_date->format('j M Y') }}</td><td>{{ $invoice->due_date?->format('j M Y') ?? '—' }}</td><td class="text-end">{{ App\Support\Money::of($invoice->total_amount)->format($invoice->currency) }}</td><td><span class="badge text-bg-{{ $invoice->status === App\SupplierInvoiceStatus::Draft ? 'secondary' : 'primary' }}">{{ str($invoice->status->value)->replace('_', ' ')->title() }}</span></td><td class="text-end"><a class="btn btn-sm btn-outline-primary" href="{{ route('supplier-invoices.show', $invoice) }}">View</a></td></tr>
    @empty<tr><td class="text-center text-secondary py-5" colspan="9">No supplier invoices found.</td></tr>@endforelse</tbody>
</table></div></div>
<div class="mt-3">{{ $supplierInvoices->links() }}</div>
@endsection
