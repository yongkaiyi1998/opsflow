<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Models\Department;
use App\Models\User;
use App\UserRole;
use App\UserStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class UserController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', User::class);
        $request->validate(['search' => ['nullable', 'string', 'max:100']]);
        $search = $request->string('search')->trim()->toString();
        $users = User::query()->with(['department', 'manager'])
            ->when($search, fn (Builder $query): Builder => $query->where(
                fn (Builder $query): Builder => $query->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhereHas('department', fn (Builder $query): Builder => $query->where('name', 'like', "%{$search}%")),
            ))->orderBy('name')->paginate(15)->withQueryString();

        return view('users.index', compact('users', 'search'));
    }

    public function create(): View
    {
        Gate::authorize('create', User::class);

        return view('users.create', [
            'departments' => Department::where('status', 'ACTIVE')->orderBy('name')->get(),
            'managers' => User::where('status', UserStatus::Active)->orderBy('name')->get(),
            'roles' => UserRole::cases(),
            'statuses' => UserStatus::cases(),
        ]);
    }

    public function store(StoreUserRequest $request): RedirectResponse
    {
        User::make()->forceFill($request->validated())->save();

        return redirect()->route('users.index')->with('success', 'User created.');
    }

    public function edit(User $user): View
    {
        Gate::authorize('update', $user);
        $departments = Department::where('status', 'ACTIVE')
            ->when($user->department_id, fn (Builder $query): Builder => $query->orWhereKey($user->department_id))
            ->orderBy('name')->get();
        $managers = User::whereKeyNot($user->id)->where(function (Builder $query) use ($user): void {
            $query->where('status', UserStatus::Active)
                ->when($user->manager_id, fn (Builder $query): Builder => $query->orWhereKey($user->manager_id));
        })->orderBy('name')->get();

        return view('users.edit', [
            'user' => $user,
            'departments' => $departments,
            'managers' => $managers,
            'roles' => UserRole::cases(),
            'statuses' => UserStatus::cases(),
        ]);
    }

    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        $attributes = $request->validated();
        if (blank($attributes['password'] ?? null)) {
            unset($attributes['password']);
        }
        $user->forceFill($attributes)->save();

        return redirect()->route('users.index')->with('success', 'User updated.');
    }
}
