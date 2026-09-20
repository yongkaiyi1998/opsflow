<nav class="app-nav" aria-label="Primary navigation">
    <div class="app-nav-section">
        <span class="app-nav-label">Main</span>
        <a class="app-nav-link {{ request()->routeIs('dashboard') ? 'active' : '' }}" href="{{ route('dashboard') }}" @if (request()->routeIs('dashboard')) aria-current="page" @endif>Dashboard</a>
        <a class="app-nav-link {{ request()->routeIs('purchase-requests.*') || request()->routeIs('purchase-request-attachments.*') ? 'active' : '' }}" href="{{ route('purchase-requests.index') }}" @if (request()->routeIs('purchase-requests.*') || request()->routeIs('purchase-request-attachments.*')) aria-current="page" @endif>Purchase Requests</a>
        @can('viewAny', App\Models\SupplierInvoice::class)
            <a class="app-nav-link {{ request()->routeIs('supplier-invoices.*') || request()->routeIs('supplier-invoice-attachments.*') ? 'active' : '' }}" href="{{ route('supplier-invoices.index') }}" @if (request()->routeIs('supplier-invoices.*') || request()->routeIs('supplier-invoice-attachments.*')) aria-current="page" @endif>Supplier Invoices</a>
        @endcan
        @can('viewAny', App\Models\IntakeBatch::class)
            <a class="app-nav-link {{ request()->routeIs('invoice-intakes.*') ? 'active' : '' }}" href="{{ route('invoice-intakes.index') }}" @if (request()->routeIs('invoice-intakes.*')) aria-current="page" @endif>Invoice Intake</a>
        @endcan
        @can('viewAny', App\Models\ExpenseClaim::class)
            <a class="app-nav-link {{ request()->routeIs('expense-claims.*') || request()->routeIs('expense-item-attachments.*') ? 'active' : '' }}" href="{{ route('expense-claims.index') }}" @if (request()->routeIs('expense-claims.*') || request()->routeIs('expense-item-attachments.*')) aria-current="page" @endif>Expense Claims</a>
        @endcan
    </div>

    <div class="app-nav-section">
        <span class="app-nav-label">Work</span>
        <a class="app-nav-link {{ request()->routeIs('approvals.*') || request()->routeIs('approval-assignments.*') ? 'active' : '' }}" href="{{ route('approvals.index') }}" @if (request()->routeIs('approvals.*') || request()->routeIs('approval-assignments.*')) aria-current="page" @endif>Approval Inbox</a>
        <a class="app-nav-link {{ request()->routeIs('notifications.*') ? 'active' : '' }}" href="{{ route('notifications.index') }}" @if (request()->routeIs('notifications.*')) aria-current="page" @endif>
            <span>Notifications</span>
            @if ($unreadNotificationCount > 0)
                <span class="app-nav-count">{{ $unreadNotificationCount }}</span>
            @endif
        </a>
    </div>

    @can('viewAny', App\Models\Department::class)
        <div class="app-nav-section">
            <span class="app-nav-label">Administration</span>
            <a class="app-nav-link {{ request()->routeIs('users.*') ? 'active' : '' }}" href="{{ route('users.index') }}">Users</a>
            <a class="app-nav-link {{ request()->routeIs('departments.*') ? 'active' : '' }}" href="{{ route('departments.index') }}">Departments</a>
            <a class="app-nav-link {{ request()->routeIs('spend-categories.*') ? 'active' : '' }}" href="{{ route('spend-categories.index') }}">Spend Categories</a>
            <a class="app-nav-link {{ request()->routeIs('vendors.*') ? 'active' : '' }}" href="{{ route('vendors.index') }}">Vendors</a>
            <a class="app-nav-link {{ request()->routeIs('workflow-*') ? 'active' : '' }}" href="{{ route('workflow-templates.index') }}">Workflows</a>
        </div>
    @endcan
</nav>
