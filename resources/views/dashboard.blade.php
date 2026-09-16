@extends('layouts.app')
@section('title', 'Dashboard')
@section('content')
<x-page-header title="Dashboard" :description="'Welcome back, '.auth()->user()->name.'. Your account and what needs attention across your OpsFlow workspace.'">
    <x-slot:actions>
        @can('create', App\Models\PurchaseRequest::class)
            <a class="btn btn-primary" href="{{ route('purchase-requests.create') }}">New purchase request</a>
        @endcan
    </x-slot:actions>
</x-page-header>

<div class="row g-3 mb-4">
    @foreach ([['My drafts', $summary['drafts']], ['Waiting approval', $summary['waiting']], ['Changes requested', $summary['changes_requested']], ['My approval inbox', $approver['pending_count']]] as [$label, $value])
        <div class="col-sm-6 col-xl-3">
            <div class="card metric-card h-100">
                <div class="card-body">
                    <div class="metric-label">{{ $label }}</div>
                    <div class="metric-value">{{ $value }}</div>
                </div>
            </div>
        </div>
    @endforeach
</div>

<div class="row g-4">
    <div class="col-xl-7">
        <section class="card h-100" aria-labelledby="recent-heading">
            <div class="card-body p-4">
                <h2 class="section-heading" id="recent-heading">Recently approved <small>Your latest completed requests and claims.</small></h2>
                <div class="list-group list-group-flush">
                    @forelse ($summary['recently_approved'] as $record)
                        <a class="list-group-item list-group-item-action px-0 py-3 d-flex flex-wrap justify-content-between gap-2" href="{{ $record['url'] }}">
                            <span><strong>{{ $record['reference'] }}</strong><span class="text-secondary ms-2">{{ $record['module'] }}</span></span>
                            <small class="text-secondary">{{ $record['approved_at']->diffForHumans() }}</small>
                        </a>
                    @empty
                        <x-empty-state title="No recent approvals" description="Completed requests will appear here for quick reference." />
                    @endforelse
                </div>
            </div>
        </section>
    </div>
    <div class="col-xl-5">
        <div class="d-flex flex-column gap-4 h-100">
            <section class="card" aria-labelledby="inbox-heading">
                <div class="card-body p-4">
                    <h2 class="section-heading" id="inbox-heading">Approval inbox <small>Items currently waiting for your decision.</small></h2>
                    <div class="d-flex align-items-end justify-content-between gap-3">
                        <div><p class="metric-value mb-1">{{ $approver['pending_count'] }}</p><p class="text-secondary small mb-0">@if ($approver['oldest_pending'])Oldest pending since {{ $approver['oldest_pending']->assigned_at->format('j M Y H:i') }}@else No approval is waiting for you.@endif</p></div>
                        <a class="btn btn-outline-primary" href="{{ route('approvals.index') }}">Open inbox</a>
                    </div>
                </div>
            </section>
            <section class="card flex-grow-1" aria-labelledby="quick-actions-heading">
                <div class="card-body p-4">
                    <h2 class="section-heading" id="quick-actions-heading">Quick actions</h2>
                    <div class="d-grid gap-2">
                        @can('create', App\Models\ExpenseClaim::class)<a class="btn btn-outline-secondary text-start" href="{{ route('expense-claims.create') }}">New expense claim</a>@endcan
                        @can('create', App\Models\SupplierInvoice::class)<a class="btn btn-outline-secondary text-start" href="{{ route('supplier-invoices.create') }}">New supplier invoice</a>@endcan
                        @can('viewAny', App\Models\WorkflowTemplate::class)<a class="btn btn-outline-secondary text-start" href="{{ route('workflow-templates.index') }}">Manage workflows</a>@endcan
                    </div>
                </div>
            </section>
        </div>
    </div>
</div>

@if ($finance)
<section class="mt-4" aria-labelledby="finance-heading">
    <h2 class="section-heading" id="finance-heading">Finance operations <small>Current invoice and reimbursement workload.</small></h2>
    <div class="row g-3">
        @foreach ([['Supplier invoices awaiting approval', $finance['invoices_awaiting_approval']], ['Expense claims requiring Finance review', $finance['claims_requiring_finance_review']], ['Approved supplier invoices', $finance['approved_invoices']], ['Approved invoices due soon', $finance['due_soon']]] as [$label, $value])
            <div class="col-sm-6 col-xl-3"><div class="card metric-card h-100"><div class="card-body"><div class="metric-label">{{ $label }}</div><div class="metric-value">{{ $value }}</div></div></div></div>
        @endforeach
    </div>
</section>
@endif

@if ($admin)
<section class="mt-4" aria-labelledby="admin-heading">
    <h2 class="section-heading" id="admin-heading">Administration <small>Configuration health and platform activity.</small></h2>
    <div class="row g-3 mb-4">
        @foreach ([['Active users', $admin['active_users']], ['Active workflows', $admin['active_workflows']], ['Blocked workflows', $admin['blocked_workflows']]] as [$label, $value])
            <div class="col-md-4"><div class="card metric-card h-100"><div class="card-body"><div class="metric-label">{{ $label }}</div><div class="metric-value">{{ $value }}</div></div></div></div>
        @endforeach
    </div>
    <div class="row g-4">
        <div class="col-lg-6"><div class="card h-100"><div class="card-body p-4"><h3 class="section-heading">Requests by status</h3><dl class="row mb-0">@forelse ($admin['requests_by_status'] as $status => $count)<dt class="col-8 fw-medium py-1">{{ str($status)->replace('_', ' ')->title() }}</dt><dd class="col-4 text-end py-1 mb-0">{{ $count }}</dd>@empty<dd class="text-secondary">No requests recorded.</dd>@endforelse</dl></div></div></div>
        <div class="col-lg-6"><div class="card h-100"><div class="card-body p-4"><h3 class="section-heading">Recent workflow publications</h3>@forelse ($admin['recent_publications'] as $version)<div class="border-bottom py-2"><strong>{{ $version->template->name }}</strong> · Version {{ $version->version }}<div class="small text-secondary">{{ $version->published_at->format('j M Y H:i') }} by {{ $version->publisher?->name ?? 'Unknown' }}</div></div>@empty<p class="text-secondary mb-0">No workflow publications recorded.</p>@endforelse</div></div></div>
    </div>
</section>
@endif
@endsection
