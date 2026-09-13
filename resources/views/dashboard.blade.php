@extends('layouts.app')
@section('title', 'Dashboard')
@section('content')
<div class="mb-4"><h1 class="h2 mb-1">Welcome, {{ auth()->user()->name }}</h1><p class="text-secondary mb-0">Your account and what needs attention across your OpsFlow workspace.</p></div>

<div class="row g-3 mb-4">
    @foreach ([['My drafts', $summary['drafts']], ['Waiting approval', $summary['waiting']], ['Changes requested', $summary['changes_requested']], ['My approval inbox', $approver['pending_count']]] as [$label, $value])
        <div class="col-sm-6 col-xl-3"><div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="text-secondary">{{ $label }}</div><div class="display-6 fw-semibold">{{ $value }}</div></div></div></div>
    @endforeach
</div>

<div class="row g-4">
    <div class="col-lg-7"><section class="card border-0 shadow-sm h-100" aria-labelledby="recent-heading"><div class="card-body"><h2 class="h5" id="recent-heading">Recently approved</h2><div class="list-group list-group-flush">
        @forelse ($summary['recently_approved'] as $record)
            <a class="list-group-item list-group-item-action px-0 d-flex justify-content-between" href="{{ $record['url'] }}"><span><strong>{{ $record['reference'] }}</strong><span class="text-secondary ms-2">{{ $record['module'] }}</span></span><small class="text-secondary">{{ $record['approved_at']->diffForHumans() }}</small></a>
        @empty<p class="text-secondary mb-0 py-3">No recently approved records.</p>@endforelse
    </div></div></section></div>
    <div class="col-lg-5"><section class="card border-0 shadow-sm h-100" aria-labelledby="inbox-heading"><div class="card-body"><h2 class="h5" id="inbox-heading">Approval inbox</h2><p class="display-6 fw-semibold mb-1">{{ $approver['pending_count'] }}</p><p class="text-secondary">@if ($approver['oldest_pending'])Oldest pending since {{ $approver['oldest_pending']->assigned_at->format('j M Y H:i') }}@else No approval is waiting for you.@endif</p><a class="btn btn-outline-primary" href="{{ route('approvals.index') }}">Open approval inbox</a></div></section></div>
</div>

@if ($finance)
<section class="mt-4" aria-labelledby="finance-heading"><h2 class="h4" id="finance-heading">Finance operations</h2><div class="row g-3">
    @foreach ([['Supplier invoices awaiting approval', $finance['invoices_awaiting_approval']], ['Expense claims requiring Finance review', $finance['claims_requiring_finance_review']], ['Approved supplier invoices', $finance['approved_invoices']], ['Approved invoices due soon', $finance['due_soon']]] as [$label, $value])
        <div class="col-sm-6 col-xl-3"><div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="text-secondary">{{ $label }}</div><div class="h2 mb-0">{{ $value }}</div></div></div></div>
    @endforeach
</div></section>
@endif

@if ($admin)
<section class="mt-4" aria-labelledby="admin-heading"><h2 class="h4" id="admin-heading">Administration</h2>
    <div class="row g-3 mb-4">@foreach ([['Active users', $admin['active_users']], ['Active workflows', $admin['active_workflows']], ['Blocked workflows', $admin['blocked_workflows']]] as [$label, $value])<div class="col-md-4"><div class="card border-0 shadow-sm"><div class="card-body"><div class="text-secondary">{{ $label }}</div><div class="h2 mb-0">{{ $value }}</div></div></div></div>@endforeach</div>
    <div class="row g-4">
        <div class="col-lg-6"><div class="card border-0 shadow-sm h-100"><div class="card-body"><h3 class="h5">Requests by status</h3><dl class="row mb-0">@forelse ($admin['requests_by_status'] as $status => $count)<dt class="col-8">{{ str($status)->replace('_', ' ')->title() }}</dt><dd class="col-4 text-end">{{ $count }}</dd>@empty<dd class="text-secondary">No requests recorded.</dd>@endforelse</dl></div></div></div>
        <div class="col-lg-6"><div class="card border-0 shadow-sm h-100"><div class="card-body"><h3 class="h5">Recent workflow publications</h3>@forelse ($admin['recent_publications'] as $version)<div class="border-bottom py-2"><strong>{{ $version->template->name }}</strong> · Version {{ $version->version }}<div class="small text-secondary">{{ $version->published_at->format('j M Y H:i') }} by {{ $version->publisher?->name ?? 'Unknown' }}</div></div>@empty<p class="text-secondary mb-0">No workflow publications recorded.</p>@endforelse</div></div></div>
    </div>
</section>
@endif
@endsection
