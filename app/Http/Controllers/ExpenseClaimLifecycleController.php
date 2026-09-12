<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreWorkflowLifecycleActionRequest;
use App\Models\ExpenseClaim;
use App\Services\ApprovalService;
use App\Services\ExpenseClaimService;
use Illuminate\Http\RedirectResponse;

class ExpenseClaimLifecycleController extends Controller
{
    public function resubmit(
        StoreWorkflowLifecycleActionRequest $request,
        ExpenseClaim $expenseClaim,
        ExpenseClaimService $service,
    ): RedirectResponse {
        $service->resubmit($expenseClaim, $request->user(), $request->validated('comment'));

        return redirect()->route('expense-claims.show', $expenseClaim)
            ->with('success', 'Expense claim resubmitted for approval.');
    }

    public function withdraw(
        StoreWorkflowLifecycleActionRequest $request,
        ExpenseClaim $expenseClaim,
        ApprovalService $service,
    ): RedirectResponse {
        $service->withdraw($expenseClaim, $request->user(), $request->validated('comment'));

        return redirect()->route('expense-claims.show', $expenseClaim)
            ->with('success', 'Expense claim withdrawn.');
    }
}
