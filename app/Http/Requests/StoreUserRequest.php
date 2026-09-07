<?php

namespace App\Http\Requests;

use App\Models\User;
use App\UserRole;
use App\UserStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', User::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique(User::class)],
            'password' => ['required', 'confirmed', Password::min(8)],
            'role' => ['required', Rule::enum(UserRole::class)],
            'status' => ['required', Rule::enum(UserStatus::class)],
            'department_id' => ['nullable', Rule::exists('departments', 'id')->where('status', 'ACTIVE')],
            'manager_id' => ['nullable', Rule::exists('users', 'id')->where('status', UserStatus::Active->value)],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['email' => strtolower($this->string('email')->trim()->toString())]);
    }
}
