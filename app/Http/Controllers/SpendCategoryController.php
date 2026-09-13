<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSpendCategoryRequest;
use App\Http\Requests\UpdateSpendCategoryRequest;
use App\Models\SpendCategory;
use App\Services\AuditService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class SpendCategoryController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', SpendCategory::class);
        $request->validate(['search' => ['nullable', 'string', 'max:100']]);
        $search = $request->string('search')->trim()->toString();
        $spendCategories = SpendCategory::query()
            ->when($search, fn (Builder $query): Builder => $query->where(
                fn (Builder $query): Builder => $query->where('name', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%"),
            ))->orderBy('name')->paginate(15)->withQueryString();

        return view('spend-categories.index', compact('spendCategories', 'search'));
    }

    public function create(): View
    {
        Gate::authorize('create', SpendCategory::class);

        return view('spend-categories.create');
    }

    public function store(StoreSpendCategoryRequest $request, AuditService $auditService): RedirectResponse
    {
        DB::transaction(function () use ($request, $auditService): void {
            $category = SpendCategory::create($request->validated());
            $auditService->logCreated($category, $request->user(), $category->only([
                'name', 'code', 'status',
            ]), $request);
        });

        return redirect()->route('spend-categories.index')->with('success', 'Spend category created.');
    }

    public function edit(SpendCategory $spendCategory): View
    {
        Gate::authorize('update', $spendCategory);

        return view('spend-categories.edit', compact('spendCategory'));
    }

    public function update(UpdateSpendCategoryRequest $request, SpendCategory $spendCategory, AuditService $auditService): RedirectResponse
    {
        DB::transaction(function () use ($request, $spendCategory, $auditService): void {
            $oldValues = $spendCategory->only(['name', 'code', 'status']);
            $spendCategory->update($request->validated());
            $auditService->logUpdated($spendCategory, $request->user(), $oldValues, $spendCategory->only([
                'name', 'code', 'status',
            ]), $request);
        });

        return redirect()->route('spend-categories.index')->with('success', 'Spend category updated.');
    }
}
