<?php

namespace Tests\Feature;

use App\ApprovalMode;
use App\ApproverType;
use App\Exceptions\ApprovalRuntimeException;
use App\Models\Department;
use App\Models\User;
use App\Models\WorkflowStep;
use App\Services\ApproverResolver;
use App\UserRole;
use App\WorkflowContext;
use App\WorkflowModuleType;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class ApproverResolverTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_requester_manager_is_resolved(): void
    {
        $manager = User::factory()->create();
        $requester = User::factory()->create(['manager_id' => $manager]);

        $approvers = app(ApproverResolver::class)->resolve(
            $this->step(ApproverType::RequesterManager),
            $this->context($requester),
        );

        $this->assertSame([$manager->id], $approvers->modelKeys());
    }

    public function test_department_manager_is_resolved(): void
    {
        $manager = User::factory()->create();
        $department = Department::factory()->create(['manager_id' => $manager]);
        $requester = User::factory()->create(['department_id' => $department]);

        $approvers = app(ApproverResolver::class)->resolve(
            $this->step(ApproverType::DepartmentManager),
            $this->context($requester, $department),
        );

        $this->assertSame([$manager->id], $approvers->modelKeys());
    }

    public function test_role_resolves_all_active_eligible_users_in_stable_order(): void
    {
        $requester = User::factory()->create(['role' => UserRole::Finance]);
        $first = User::factory()->create(['role' => UserRole::Finance]);
        User::factory()->inactive()->create(['role' => UserRole::Finance]);
        $second = User::factory()->create(['role' => UserRole::Finance]);

        $approvers = app(ApproverResolver::class)->resolve(
            $this->step(ApproverType::Role, UserRole::Finance->value),
            $this->context($requester),
        );

        $this->assertSame([$first->id, $second->id], $approvers->modelKeys());
    }

    public function test_specific_active_user_is_resolved(): void
    {
        $requester = User::factory()->create();
        $specificUser = User::factory()->create();

        $approvers = app(ApproverResolver::class)->resolve(
            $this->step(ApproverType::SpecificUser, (string) $specificUser->id),
            $this->context($requester),
        );

        $this->assertSame([$specificUser->id], $approvers->modelKeys());
    }

    public function test_missing_manager_fails_safely(): void
    {
        $requester = User::factory()->create(['manager_id' => null]);

        $this->expectException(ApprovalRuntimeException::class);
        $this->expectExceptionMessage('A required approver is unavailable. Please contact an administrator.');

        app(ApproverResolver::class)->resolve(
            $this->step(ApproverType::RequesterManager),
            $this->context($requester),
        );
    }

    public function test_inactive_approver_fails_safely(): void
    {
        $inactiveApprover = User::factory()->inactive()->create();
        $requester = User::factory()->create(['manager_id' => $inactiveApprover]);

        $this->expectException(ApprovalRuntimeException::class);

        app(ApproverResolver::class)->resolve(
            $this->step(ApproverType::RequesterManager),
            $this->context($requester),
        );
    }

    public function test_self_approval_fails_safely_for_relationship_and_specific_user_strategies(): void
    {
        $requester = User::factory()->create();
        $requester->update(['manager_id' => $requester->id]);
        $resolver = app(ApproverResolver::class);

        foreach ([
            $this->step(ApproverType::RequesterManager),
            $this->step(ApproverType::SpecificUser, (string) $requester->id),
        ] as $step) {
            try {
                $resolver->resolve($step, $this->context($requester));
                $this->fail('Self-approval must not produce an eligible approver.');
            } catch (ApprovalRuntimeException $exception) {
                $this->assertSame('A required approver is unavailable. Please contact an administrator.', $exception->getMessage());
            }
        }
    }

    public function test_role_with_only_the_requester_or_an_invalid_value_fails_safely(): void
    {
        $requester = User::factory()->create(['role' => UserRole::Finance]);
        $resolver = app(ApproverResolver::class);

        foreach ([UserRole::Finance->value, 'UNKNOWN'] as $role) {
            try {
                $resolver->resolve($this->step(ApproverType::Role, $role), $this->context($requester));
                $this->fail('A role without an eligible approver must fail.');
            } catch (ApprovalRuntimeException $exception) {
                $this->assertSame('A required approver is unavailable. Please contact an administrator.', $exception->getMessage());
            }
        }
    }

    private function context(User $requester, ?Department $department = null): WorkflowContext
    {
        return WorkflowContext::fromValues(
            WorkflowModuleType::PurchaseRequest,
            $requester->id,
            $department?->id,
            null,
            '100.00',
            'MYR',
        );
    }

    private function step(ApproverType $type, ?string $value = null): WorkflowStep
    {
        $step = new WorkflowStep;
        $step->setRawAttributes([
            'approver_type' => $type->value,
            'approver_value' => $value,
            'approval_mode' => ApprovalMode::Any->value,
        ], true);

        return $step;
    }
}
