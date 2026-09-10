<?php

namespace App\Http\Controllers;

use App\ApprovalAssignmentStatus;
use App\ExpenseClaimStatus;
use App\Http\Requests\StoreExpenseClaimRequest;
use App\Http\Requests\UpdateExpenseClaimRequest;
use App\MasterDataStatus;
use App\Models\ExpenseClaim;
use App\Models\SpendCategory;
use App\Services\ExpenseClaimService;
use App\UserRole;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ExpenseClaimController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', ExpenseClaim::class);
        $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(ExpenseClaimStatus::class)],
            'category_id' => ['nullable', 'integer'],
        ]);
        $search = $request->string('search')->trim()->toString();
        $status = $request->string('status')->toString();
        $categoryId = $request->integer('category_id') ?: null;
        $user = $request->user();
        $hasOperationalVisibility = in_array($user->role, [UserRole::Finance, UserRole::Admin], true);
        $expenseClaims = ExpenseClaim::query()
            ->with(['employee', 'department'])
            ->unless($hasOperationalVisibility, fn (Builder $query): Builder => $query->where(
                fn (Builder $query): Builder => $query->where('employee_id', $user->id)
                    ->orWhereHas('approvalInstances.steps.assignments', fn (Builder $query): Builder => $query
                        ->where('approver_id', $user->id)
                        ->where('status', ApprovalAssignmentStatus::Pending->value)),
            ))
            ->when($search, fn (Builder $query): Builder => $query->where(
                fn (Builder $query): Builder => $query->where('claim_no', 'like', "%{$search}%")
                    ->orWhere('title', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhereRelation('employee', 'name', 'like', "%{$search}%"),
            ))
            ->when($status, fn (Builder $query): Builder => $query->where('status', $status))
            ->when($categoryId, fn (Builder $query): Builder => $query->whereHas('items', fn (Builder $query): Builder => $query->where('category_id', $categoryId)))
            ->latest()
            ->latest('id')
            ->paginate(15)
            ->withQueryString();

        return view('expense-claims.index', array_merge(
            compact('expenseClaims', 'search', 'status', 'categoryId'),
            $this->formData(),
        ));
    }

    public function create(): View
    {
        Gate::authorize('create', ExpenseClaim::class);

        return view('expense-claims.create', $this->formData());
    }

    public function store(StoreExpenseClaimRequest $request, ExpenseClaimService $service): RedirectResponse
    {
        $expenseClaim = $service->create($request->validated(), $request->user());

        return redirect()->route('expense-claims.show', $expenseClaim)->with('success', 'Expense claim created. Add a receipt to every item before submission.');
    }

    public function show(ExpenseClaim $expenseClaim): View
    {
        Gate::authorize('view', $expenseClaim);
        $expenseClaim->load([
            'employee', 'department', 'items.category', 'items.attachments.uploadedBy',
            'approvalInstances.workflowVersion', 'approvalInstances.workflowRuleGroup',
            'approvalInstances.steps.assignments.approver', 'approvalInstances.actions.actor',
        ]);

        return view('expense-claims.show', compact('expenseClaim'));
    }

    public function edit(ExpenseClaim $expenseClaim): View
    {
        Gate::authorize('update', $expenseClaim);
        $expenseClaim->load('items');

        return view('expense-claims.edit', array_merge(['expenseClaim' => $expenseClaim], $this->formData()));
    }

    public function update(UpdateExpenseClaimRequest $request, ExpenseClaim $expenseClaim, ExpenseClaimService $service): RedirectResponse
    {
        $expenseClaim = $service->update($expenseClaim, $request->validated(), $request->user());

        return redirect()->route('expense-claims.show', $expenseClaim)->with('success', 'Expense claim updated.');
    }

    public function destroy(Request $request, ExpenseClaim $expenseClaim, ExpenseClaimService $service): RedirectResponse
    {
        $service->deleteDraft($expenseClaim, $request->user());

        return redirect()->route('expense-claims.index')->with('success', 'Draft expense claim deleted.');
    }

    /** @return array{categories: Collection<int, SpendCategory>} */
    private function formData(): array
    {
        return [
            'categories' => SpendCategory::query()->where('status', MasterDataStatus::Active->value)->orderBy('name')->get(),
        ];
    }
}
