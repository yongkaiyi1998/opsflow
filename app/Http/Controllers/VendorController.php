<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreVendorRequest;
use App\Http\Requests\UpdateVendorRequest;
use App\Models\Vendor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class VendorController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Vendor::class);
        $request->validate(['search' => ['nullable', 'string', 'max:100']]);
        $search = $request->string('search')->trim()->toString();
        $vendors = Vendor::query()
            ->when($search, fn (Builder $query): Builder => $query->where(
                fn (Builder $query): Builder => $query->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%"),
            ))->orderBy('name')->paginate(15)->withQueryString();

        return view('vendors.index', compact('vendors', 'search'));
    }

    public function create(): View
    {
        Gate::authorize('create', Vendor::class);

        return view('vendors.create');
    }

    public function store(StoreVendorRequest $request): RedirectResponse
    {
        Vendor::create($request->validated());

        return redirect()->route('vendors.index')->with('success', 'Vendor created.');
    }

    public function edit(Vendor $vendor): View
    {
        Gate::authorize('update', $vendor);

        return view('vendors.edit', compact('vendor'));
    }

    public function update(UpdateVendorRequest $request, Vendor $vendor): RedirectResponse
    {
        $vendor->update($request->validated());

        return redirect()->route('vendors.index')->with('success', 'Vendor updated.');
    }
}
