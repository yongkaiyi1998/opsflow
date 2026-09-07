<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSpendCategoryRequest;
use App\Http\Requests\UpdateSpendCategoryRequest;
use App\Models\SpendCategory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

    public function store(StoreSpendCategoryRequest $request): RedirectResponse
    {
        SpendCategory::create($request->validated());

        return redirect()->route('spend-categories.index')->with('success', 'Spend category created.');
    }

    public function edit(SpendCategory $spendCategory): View
    {
        Gate::authorize('update', $spendCategory);

        return view('spend-categories.edit', compact('spendCategory'));
    }

    public function update(UpdateSpendCategoryRequest $request, SpendCategory $spendCategory): RedirectResponse
    {
        $spendCategory->update($request->validated());

        return redirect()->route('spend-categories.index')->with('success', 'Spend category updated.');
    }
}
