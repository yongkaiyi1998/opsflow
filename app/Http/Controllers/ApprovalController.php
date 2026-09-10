<?php

namespace App\Http\Controllers;

use App\ApprovalAssignmentStatus;
use App\ApprovalInstanceStatus;
use App\ApprovalStepStatus;
use App\Http\Requests\StoreApprovalActionRequest;
use App\Models\ApprovalAssignment;
use App\Models\ExpenseClaim;
use App\Models\PurchaseRequest;
use App\Models\SupplierInvoice;
use App\Services\ApprovalService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class ApprovalController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', ApprovalAssignment::class);
        $assignments = ApprovalAssignment::query()
            ->where('approver_id', $request->user()->id)
            ->where('status', ApprovalAssignmentStatus::Pending->value)
            ->whereHas('step', fn ($query) => $query
                ->where('status', ApprovalStepStatus::Active->value)
                ->whereHas('approvalInstance', fn ($query) => $query
                    ->where('status', ApprovalInstanceStatus::InProgress->value)
                    ->whereHasMorph('approvable', [
                        PurchaseRequest::class,
                        SupplierInvoice::class,
                        ExpenseClaim::class,
                    ], fn ($query) => $query->where('status', 'IN_APPROVAL'))))
            ->with([
                'step.approvalInstance.approvable' => fn (MorphTo $morphTo) => $morphTo->morphWith([
                    PurchaseRequest::class => ['requester'],
                    SupplierInvoice::class => ['submittedBy'],
                    ExpenseClaim::class => ['employee'],
                ]),
            ])
            ->latest('assigned_at')
            ->latest('id')
            ->paginate(15);

        return view('approvals.index', compact('assignments'));
    }

    public function show(ApprovalAssignment $approvalAssignment): View
    {
        Gate::authorize('view', $approvalAssignment);
        $approvalAssignment->load([
            'approver', 'step.approvalInstance.workflowVersion',
            'step.approvalInstance.workflowRuleGroup',
            'step.approvalInstance.steps.assignments.approver',
            'step.approvalInstance.actions.actor',
            'step.approvalInstance.actions.step',
            'step.approvalInstance.approvable',
        ]);
        $instance = $approvalAssignment->step->approvalInstance;
        $business = $instance->approvable;

        if (! $business instanceof PurchaseRequest
            && ! $business instanceof SupplierInvoice
            && ! $business instanceof ExpenseClaim) {
            abort(404);
        }

        $this->loadBusinessDetails($business);
        $actionable = Gate::allows('approve', $approvalAssignment)
            && $approvalAssignment->status === ApprovalAssignmentStatus::Pending
            && $approvalAssignment->step->status === ApprovalStepStatus::Active
            && $instance->status === ApprovalInstanceStatus::InProgress
            && $instance->current_step_order === $approvalAssignment->step->step_order
            && (string) $business->getRawOriginal('status') === 'IN_APPROVAL';

        return view('approvals.show', compact('approvalAssignment', 'instance', 'business', 'actionable'));
    }

    public function approve(
        StoreApprovalActionRequest $request,
        ApprovalAssignment $approvalAssignment,
        ApprovalService $service,
    ): RedirectResponse {
        $service->approve($approvalAssignment, $request->user(), $request->validated('comment'));

        return redirect()->route('approvals.index')->with('success', 'Approval recorded.');
    }

    public function reject(
        StoreApprovalActionRequest $request,
        ApprovalAssignment $approvalAssignment,
        ApprovalService $service,
    ): RedirectResponse {
        $service->reject($approvalAssignment, $request->user(), $request->validated('comment'));

        return redirect()->route('approvals.index')->with('success', 'Request rejected.');
    }

    public function requestChanges(
        StoreApprovalActionRequest $request,
        ApprovalAssignment $approvalAssignment,
        ApprovalService $service,
    ): RedirectResponse {
        $service->requestChanges($approvalAssignment, $request->user(), $request->validated('comment'));

        return redirect()->route('approvals.index')->with('success', 'Changes requested.');
    }

    private function loadBusinessDetails(Model $business): void
    {
        match (true) {
            $business instanceof PurchaseRequest => $business->load([
                'requester', 'department', 'category', 'vendor', 'items', 'attachments.uploadedBy',
            ]),
            $business instanceof SupplierInvoice => $business->load([
                'submittedBy', 'department', 'category', 'vendor', 'items', 'attachments.uploadedBy',
            ]),
            $business instanceof ExpenseClaim => $business->load([
                'employee', 'department', 'items.category', 'items.attachments.uploadedBy',
            ]),
            default => null,
        };
    }
}
