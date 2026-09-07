<?php

namespace App\Http\Requests;

use App\Models\User;
use App\UserRole;
use App\UserStatus;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('user')) ?? false;
    }

    public function rules(): array
    {
        $user = $this->route('user');

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique(User::class)->ignore($user)],
            'password' => ['nullable', 'confirmed', Password::min(8)],
            'role' => ['required', Rule::enum(UserRole::class)],
            'status' => ['required', Rule::enum(UserStatus::class)],
            'department_id' => ['nullable', Rule::exists('departments', 'id')->where(
                fn (Builder $query): Builder => $query->where('status', 'ACTIVE')
                    ->when($user->department_id, fn (Builder $query): Builder => $query->orWhere('id', $user->department_id)),
            )],
            'manager_id' => ['nullable', Rule::notIn([$user->id]), Rule::exists('users', 'id')->where(
                fn (Builder $query): Builder => $query->where('status', UserStatus::Active->value)
                    ->when($user->manager_id, fn (Builder $query): Builder => $query->orWhere('id', $user->manager_id)),
            )],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $target = $this->route('user');
            if ($this->user()?->is($target)
                && ($this->input('role') !== UserRole::Admin->value || $this->input('status') !== UserStatus::Active->value)) {
                $validator->errors()->add('status', 'You cannot remove your own active administrator access.');
            }
        }];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['email' => strtolower($this->string('email')->trim()->toString())]);
    }
}
