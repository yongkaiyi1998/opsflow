<?php

namespace App\Http\Controllers;

use App\ApprovalAssignmentStatus;
use App\ApprovalInstanceStatus;
use App\ApprovalStepStatus;
use App\ExpenseClaimStatus;
use App\Models\ApprovalAssignment;
use App\Models\ApprovalInstance;
use App\Models\ExpenseClaim;
use App\Models\PurchaseRequest;
use App\Models\SupplierInvoice;
use App\Models\User;
use App\Models\WorkflowVersion;
use App\SupplierInvoiceStatus;
use App\UserRole;
use App\UserStatus;
use App\WorkflowVersionStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        $user = $request->user();
        $summary = $this->employeeSummary($user);
        $approver = $this->approverSummary($user);
        $finance = in_array($user->role, [UserRole::Finance, UserRole::Admin], true)
            ? $this->financeSummary()
            : null;
        $admin = $user->role === UserRole::Admin ? $this->adminSummary() : null;

        return view('dashboard', compact('summary', 'approver', 'finance', 'admin'));
    }

    /** @return array{drafts: int, waiting: int, changes_requested: int, recently_approved: Collection<int, array<string, mixed>>} */
    private function employeeSummary(User $user): array
    {
        $ownedQueries = [
            PurchaseRequest::query()->where('requester_id', $user->id),
            ExpenseClaim::query()->where('employee_id', $user->id),
            SupplierInvoice::query()->where('submitted_by', $user->id),
        ];
        $count = fn (string $status): int => collect($ownedQueries)
            ->sum(fn (Builder $query): int => (clone $query)->where('status', $status)->count());
        $recent = collect()
            ->concat($this->recentApprovals(PurchaseRequest::query()->where('requester_id', $user->id), 'Purchase Request', 'request_no', 'purchase-requests.show'))
            ->concat($this->recentApprovals(ExpenseClaim::query()->where('employee_id', $user->id), 'Expense Claim', 'claim_no', 'expense-claims.show'))
            ->concat($this->recentApprovals(SupplierInvoice::query()->where('submitted_by', $user->id), 'Supplier Invoice', 'internal_no', 'supplier-invoices.show'))
            ->sortByDesc('approved_at')
            ->take(5)
            ->values();

        return [
            'drafts' => $count('DRAFT'),
            'waiting' => $count('IN_APPROVAL'),
            'changes_requested' => $count('CHANGES_REQUESTED'),
            'recently_approved' => $recent,
        ];
    }

    /** @return array{pending_count: int, oldest_pending: ?ApprovalAssignment} */
    private function approverSummary(User $user): array
    {
        $query = $this->pendingAssignments($user);

        return [
            'pending_count' => (clone $query)->count(),
            'oldest_pending' => (clone $query)->oldest('assigned_at')->oldest('id')->first(),
        ];
    }

    /** @return array<string, int> */
    private function financeSummary(): array
    {
        $financeClaims = ExpenseClaim::query()
            ->where('status', ExpenseClaimStatus::InApproval->value)
            ->whereHas('approvalInstances', fn (Builder $query): Builder => $query
                ->where('status', ApprovalInstanceStatus::InProgress->value)
                ->whereHas('steps', fn (Builder $query): Builder => $query
                    ->where('status', ApprovalStepStatus::Active->value)
                    ->whereHas('assignments', fn (Builder $query): Builder => $query
                        ->where('status', ApprovalAssignmentStatus::Pending->value)
                        ->whereHas('approver', fn (Builder $query): Builder => $query
                            ->where('role', UserRole::Finance->value)
                            ->where('status', UserStatus::Active->value)))));

        return [
            'invoices_awaiting_approval' => SupplierInvoice::where('status', SupplierInvoiceStatus::InApproval->value)->count(),
            'claims_requiring_finance_review' => $financeClaims->count(),
            'approved_invoices' => SupplierInvoice::where('status', SupplierInvoiceStatus::Approved->value)->count(),
            'due_soon' => SupplierInvoice::query()
                ->where('status', SupplierInvoiceStatus::Approved->value)
                ->whereBetween('due_date', [today(), today()->addDays(7)])
                ->count(),
        ];
    }

    /** @return array<string, mixed> */
    private function adminSummary(): array
    {
        $statusCounts = collect();

        foreach ([PurchaseRequest::query(), SupplierInvoice::query(), ExpenseClaim::query()] as $query) {
            foreach ($query->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status') as $status => $total) {
                $statusCounts->put($status, (int) $statusCounts->get($status, 0) + (int) $total);
            }
        }

        return [
            'active_users' => User::where('status', UserStatus::Active->value)->count(),
            'active_workflows' => WorkflowVersion::where('status', WorkflowVersionStatus::Published->value)->count(),
            'requests_by_status' => $statusCounts,
            'recent_publications' => WorkflowVersion::query()
                ->whereNotNull('published_at')
                ->with(['template', 'publisher'])
                ->latest('published_at')
                ->latest('id')
                ->limit(5)
                ->get(),
            'blocked_workflows' => ApprovalInstance::where('status', ApprovalInstanceStatus::Blocked->value)->count(),
        ];
    }

    private function pendingAssignments(User $user): Builder
    {
        return ApprovalAssignment::query()
            ->where('approver_id', $user->id)
            ->where('status', ApprovalAssignmentStatus::Pending->value)
            ->whereHas('step', fn (Builder $query): Builder => $query
                ->where('status', ApprovalStepStatus::Active->value)
                ->whereHas('approvalInstance', fn (Builder $query): Builder => $query
                    ->where('status', ApprovalInstanceStatus::InProgress->value)
                    ->whereHasMorph(
                        'approvable',
                        [PurchaseRequest::class, SupplierInvoice::class, ExpenseClaim::class],
                        fn (Builder $query): Builder => $query->where('status', 'IN_APPROVAL'),
                    )));
    }

    /** @return Collection<int, array<string, mixed>> */
    private function recentApprovals(Builder $query, string $module, string $referenceColumn, string $routeName): Collection
    {
        return $query->where('status', 'APPROVED')
            ->whereNotNull('approved_at')
            ->latest('approved_at')
            ->limit(5)
            ->get(['id', $referenceColumn, 'approved_at'])
            ->map(fn ($record): array => [
                'module' => $module,
                'reference' => $record->getAttribute($referenceColumn),
                'approved_at' => $record->approved_at,
                'url' => route($routeName, $record, false),
            ]);
    }
}
