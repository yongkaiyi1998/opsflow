<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDepartmentRequest;
use App\Http\Requests\UpdateDepartmentRequest;
use App\Models\Department;
use App\Models\User;
use App\UserStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class DepartmentController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Department::class);
        $request->validate(['search' => ['nullable', 'string', 'max:100']]);
        $search = $request->string('search')->trim()->toString();
        $departments = Department::query()->with('manager')
            ->when($search, fn (Builder $query): Builder => $query->where(
                fn (Builder $query): Builder => $query->where('name', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%"),
            ))->orderBy('name')->paginate(15)->withQueryString();

        return view('departments.index', compact('departments', 'search'));
    }

    public function create(): View
    {
        Gate::authorize('create', Department::class);

        return view('departments.create', ['managers' => User::where('status', UserStatus::Active)->orderBy('name')->get()]);
    }

    public function store(StoreDepartmentRequest $request): RedirectResponse
    {
        Department::create($request->validated());

        return redirect()->route('departments.index')->with('success', 'Department created.');
    }

    public function edit(Department $department): View
    {
        Gate::authorize('update', $department);
        $managers = User::where('status', UserStatus::Active)
            ->when($department->manager_id, fn (Builder $query): Builder => $query->orWhereKey($department->manager_id))
            ->orderBy('name')->get();

        return view('departments.edit', compact('department', 'managers'));
    }

    public function update(UpdateDepartmentRequest $request, Department $department): RedirectResponse
    {
        $department->update($request->validated());

        return redirect()->route('departments.index')->with('success', 'Department updated.');
    }
}
