<?php

namespace App\Services;

use App\ApproverType;
use App\Exceptions\ApprovalRuntimeException;
use App\Models\ApprovalStepInstance;
use App\Models\Department;
use App\Models\User;
use App\Models\WorkflowStep;
use App\UserRole;
use App\UserStatus;
use App\WorkflowContext;
use Illuminate\Database\Eloquent\Collection;

class ApproverResolver
{
    /** @return Collection<int, User> */
    public function resolve(WorkflowStep|ApprovalStepInstance $step, WorkflowContext $context): Collection
    {
        $type = ApproverType::tryFrom((string) $step->getRawOriginal('approver_type'));
        $value = $step->getRawOriginal('approver_value');

        if ($type === null) {
            throw ApprovalRuntimeException::unavailableApprover();
        }

        $approvers = match ($type) {
            ApproverType::RequesterManager => $this->requesterManager($context),
            ApproverType::DepartmentManager => $this->departmentManager($context),
            ApproverType::Role => $this->usersWithRole($value, $context),
            ApproverType::SpecificUser => $this->specificUser($value),
        };

        $eligibleApprovers = $approvers
            ->filter(fn (User $user): bool => $user->status === UserStatus::Active && $user->id !== $context->requesterId)
            ->sortBy('id')
            ->values();

        if ($eligibleApprovers->isEmpty()) {
            throw ApprovalRuntimeException::unavailableApprover();
        }

        return $eligibleApprovers;
    }

    /** @return Collection<int, User> */
    private function requesterManager(WorkflowContext $context): Collection
    {
        $managerId = User::query()->whereKey($context->requesterId)->value('manager_id');

        return $managerId === null
            ? new Collection
            : User::query()->whereKey($managerId)->get();
    }

    /** @return Collection<int, User> */
    private function departmentManager(WorkflowContext $context): Collection
    {
        if ($context->departmentId === null) {
            return new Collection;
        }

        $managerId = Department::query()->whereKey($context->departmentId)->value('manager_id');

        return $managerId === null
            ? new Collection
            : User::query()->whereKey($managerId)->get();
    }

    /** @return Collection<int, User> */
    private function usersWithRole(mixed $value, WorkflowContext $context): Collection
    {
        $role = is_string($value) ? UserRole::tryFrom($value) : null;

        if ($role === null) {
            throw ApprovalRuntimeException::unavailableApprover();
        }

        return User::query()
            ->where('role', $role->value)
            ->where('status', UserStatus::Active->value)
            ->whereKeyNot($context->requesterId)
            ->orderBy('id')
            ->get();
    }

    /** @return Collection<int, User> */
    private function specificUser(mixed $value): Collection
    {
        if (! is_string($value) || ! ctype_digit($value) || (int) $value < 1) {
            throw ApprovalRuntimeException::unavailableApprover();
        }

        return User::query()->whereKey((int) $value)->get();
    }
}
