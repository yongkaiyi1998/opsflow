@extends('layouts.app')
@section('title', 'Supplier invoices')
@section('content')
<x-page-header title="Supplier invoices" description="Record and track invoices awaiting internal approval.">
    <x-slot:actions><a class="btn btn-primary" href="{{ route('supplier-invoices.create') }}">New invoice</a></x-slot:actions>
</x-page-header>

<form class="filter-panel" method="GET">
    <div class="row g-2 align-items-center">
        <div class="col-lg-4"><label class="visually-hidden" for="search">Search</label><input class="form-control" id="search" name="search" value="{{ $search }}" placeholder="Search number, vendor or description"></div>
        <div class="col-md-6 col-lg-2"><label class="visually-hidden" for="status">Status</label><select class="form-select" id="status" name="status"><option value="">All statuses</option>@foreach (App\SupplierInvoiceStatus::cases() as $option)<option value="{{ $option->value }}" @selected($status === $option->value)>{{ str($option->value)->replace('_', ' ')->title() }}</option>@endforeach</select></div>
        <div class="col-md-6 col-lg-2"><label class="visually-hidden" for="vendor_id">Vendor</label><select class="form-select" id="vendor_id" name="vendor_id"><option value="">All vendors</option>@foreach ($vendors as $vendor)<option value="{{ $vendor->id }}" @selected($vendorId === $vendor->id)>{{ $vendor->name }}</option>@endforeach</select></div>
        <div class="col-md-6 col-lg-2"><label class="visually-hidden" for="department_id">Department</label><select class="form-select" id="department_id" name="department_id"><option value="">All departments</option>@foreach ($departments as $department)<option value="{{ $department->id }}" @selected($departmentId === $department->id)>{{ $department->name }}</option>@endforeach</select></div>
        <div class="col-md-6 col-lg-2"><label class="visually-hidden" for="category_id">Category</label><select class="form-select" id="category_id" name="category_id"><option value="">All categories</option>@foreach ($categories as $category)<option value="{{ $category->id }}" @selected($categoryId === $category->id)>{{ $category->name }}</option>@endforeach</select></div>
        <div class="col-md-auto"><button class="btn btn-outline-secondary" type="submit">Apply filters</button></div>
        @if ($search || $status || $vendorId || $departmentId || $categoryId)<div class="col-md-auto"><a class="btn btn-link" href="{{ route('supplier-invoices.index') }}">Clear</a></div>@endif
    </div>
</form>

<div class="card table-card"><div class="table-responsive"><table class="table table-hover align-middle mb-0">
    <thead><tr><th>Internal no.</th><th>Supplier invoice</th><th>Vendor</th><th>Department</th><th>Invoice date</th><th>Due date</th><th class="text-end">Total</th><th>Status</th><th class="text-end">Action</th></tr></thead>
    <tbody>
    @forelse ($supplierInvoices as $invoice)
        <tr><td class="fw-semibold text-nowrap">{{ $invoice->internal_no }}</td><td class="fw-medium">{{ $invoice->invoice_no }}</td><td>{{ $invoice->vendor->name }}</td><td>{{ $invoice->department->name }}</td><td class="text-nowrap">{{ $invoice->invoice_date->format('j M Y') }}</td><td class="text-nowrap">{{ $invoice->due_date?->format('j M Y') ?? '—' }}</td><td class="text-end text-nowrap fw-medium">{{ App\Support\Money::of($invoice->total_amount)->format($invoice->currency) }}</td><td><x-status-badge :status="$invoice->status" /></td><td class="text-end"><a class="btn btn-sm btn-outline-primary" href="{{ route('supplier-invoices.show', $invoice) }}">View</a></td></tr>
    @empty
        <tr><td colspan="9"><x-empty-state :title="$search || $status || $vendorId || $departmentId || $categoryId ? 'No matching invoices' : 'No supplier invoices yet'" :description="$search || $status || $vendorId || $departmentId || $categoryId ? 'Try adjusting or clearing your filters.' : 'Record an invoice when it is ready for internal approval.'" /></td></tr>
    @endforelse
    </tbody>
</table></div></div>
<div class="mt-3">{{ $supplierInvoices->links() }}</div>
@endsection
