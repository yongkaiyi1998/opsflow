<?php

namespace Tests\Feature;

use App\ApprovalActionType;
use App\ApprovalAssignmentStatus;
use App\ApprovalInstanceStatus;
use App\ApprovalMode;
use App\ApprovalStepStatus;
use App\ApproverType;
use App\Exceptions\ApprovalRuntimeException;
use App\Models\Department;
use App\Models\User;
use App\Models\WorkflowRuleGroup;
use App\Models\WorkflowTemplate;
use App\Models\WorkflowVersion;
use App\Services\WorkflowEngine;
use App\Services\WorkflowResolver;
use App\UserRole;
use App\WorkflowContext;
use App\WorkflowModuleType;
use App\WorkflowResolution;
use App\WorkflowVersionStatus;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

class WorkflowEngineTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_start_creates_a_complete_frozen_runtime_and_activates_only_the_first_step(): void
    {
        $manager = User::factory()->create();
        $department = Department::factory()->create();
        $requester = User::factory()->create(['manager_id' => $manager, 'department_id' => $department]);
        [$version, $group] = $this->publishedRoute([
            [ApproverType::RequesterManager, null, 'Manager review'],
            [ApproverType::Role, UserRole::Finance->value, 'Finance review'],
        ]);
        $context = $this->context($requester, $department);
        $resolution = app(WorkflowResolver::class)->resolve($context);

        $instance = app(WorkflowEngine::class)->start($department, $resolution);

        $this->assertSame($version->id, $instance->workflow_version_id);
        $this->assertSame($group->id, $instance->workflow_rule_group_id);
        $this->assertSame(ApprovalInstanceStatus::InProgress, $instance->status);
        $this->assertSame(1, $instance->current_step_order);
        $this->assertTrue($instance->approvable->is($department));
        $this->assertTrue($instance->workflowVersion->is($version));
        $this->assertTrue($instance->workflowRuleGroup->is($group));
        $this->assertTrue($version->approvalInstances()->firstOrFail()->is($instance));
        $this->assertTrue($group->approvalInstances()->firstOrFail()->is($instance));
        $this->assertSame($context->snapshot(), $instance->workflow_context);
        $this->assertSame($context->snapshot(), $instance->context()->snapshot());

        $this->assertCount(2, $instance->steps);
        $this->assertSame([ApprovalStepStatus::Active, ApprovalStepStatus::Waiting], $instance->steps->pluck('status')->all());
        $this->assertSame(['Manager review', 'Finance review'], $instance->steps->pluck('name')->all());
        $this->assertSame([ApproverType::RequesterManager, ApproverType::Role], $instance->steps->pluck('approver_type')->all());
        $this->assertSame([ApprovalMode::Any, ApprovalMode::Any], $instance->steps->pluck('approval_mode')->all());
        $this->assertSame([1, 1], $instance->steps->pluck('required_approvals')->all());
        $this->assertNotNull($instance->steps->first()->started_at);
        $this->assertNull($instance->steps->last()->started_at);

        $this->assertCount(1, $instance->steps->first()->assignments);
        $this->assertSame($manager->id, $instance->steps->first()->assignments->first()->approver_id);
        $this->assertSame(ApprovalAssignmentStatus::Pending, $instance->steps->first()->assignments->first()->status);
        $this->assertCount(0, $instance->steps->last()->assignments);

        $this->assertCount(1, $instance->actions);
        $this->assertSame(ApprovalActionType::Submitted, $instance->actions->first()->action);
        $this->assertSame($requester->id, $instance->actions->first()->actor_id);
        $this->assertTrue($instance->actions->first()->actor->is($requester));
        $this->assertTrue($instance->steps->first()->approvalInstance->is($instance));
        $this->assertTrue($instance->steps->first()->workflowStep->runtimeSteps->first()->is($instance->steps->first()));
        $this->assertTrue($instance->steps->first()->assignments->first()->step->is($instance->steps->first()));
        $this->assertTrue($instance->steps->first()->assignments->first()->approver->is($manager));
    }

    public function test_future_approvers_are_not_resolved_during_startup(): void
    {
        $manager = User::factory()->create();
        $department = Department::factory()->create();
        $requester = User::factory()->create(['manager_id' => $manager]);
        $this->publishedRoute([
            [ApproverType::RequesterManager, null, 'Manager review'],
            [ApproverType::SpecificUser, '999999', 'Future approver'],
        ]);
        $resolution = app(WorkflowResolver::class)->resolve($this->context($requester));

        $instance = app(WorkflowEngine::class)->start($department, $resolution);

        $this->assertDatabaseCount('approval_instances', 1);
        $this->assertCount(0, $instance->steps->last()->assignments);
    }

    public function test_runtime_step_snapshot_does_not_change_with_configuration_rows(): void
    {
        $manager = User::factory()->create();
        $approvable = Department::factory()->create();
        $requester = User::factory()->create(['manager_id' => $manager]);
        $this->publishedRoute([[ApproverType::RequesterManager, null, 'Original name']]);
        $resolution = app(WorkflowResolver::class)->resolve($this->context($requester));
        $instance = app(WorkflowEngine::class)->start($approvable, $resolution);
        $configuredStepId = $resolution->steps()->first()->id;

        DB::table('workflow_steps')->where('id', $configuredStepId)->update(['name' => 'Changed name']);

        $this->assertSame('Original name', $instance->steps()->firstOrFail()->name);
    }

    public function test_duplicate_active_runtime_is_rejected_without_creating_more_records(): void
    {
        $manager = User::factory()->create();
        $approvable = Department::factory()->create();
        $requester = User::factory()->create(['manager_id' => $manager]);
        $this->publishedRoute([[ApproverType::RequesterManager, null, 'Manager review']]);
        $resolution = app(WorkflowResolver::class)->resolve($this->context($requester));
        $engine = app(WorkflowEngine::class);
        $engine->start($approvable, $resolution);

        try {
            $engine->start($approvable->fresh(), $resolution);
            $this->fail('A second active runtime must be rejected.');
        } catch (ApprovalRuntimeException $exception) {
            $this->assertSame('An active approval process already exists for this record.', $exception->getMessage());
        }

        $this->assertDatabaseCount('approval_instances', 1);
        $this->assertDatabaseCount('approval_step_instances', 1);
        $this->assertDatabaseCount('approval_assignments', 1);
        $this->assertDatabaseCount('approval_actions', 1);
    }

    public function test_self_approval_failure_rolls_back_all_runtime_records(): void
    {
        $approvable = Department::factory()->create();
        $requester = User::factory()->create();
        $this->publishedRoute([[ApproverType::SpecificUser, (string) $requester->id, 'Self approval']]);
        $resolution = app(WorkflowResolver::class)->resolve($this->context($requester));

        $this->assertStartupFailsWithoutPartialRuntime($approvable, $resolution);
    }

    public function test_inactive_approver_failure_rolls_back_all_runtime_records(): void
    {
        $approvable = Department::factory()->create();
        $inactiveManager = User::factory()->inactive()->create();
        $requester = User::factory()->create(['manager_id' => $inactiveManager]);
        $this->publishedRoute([[ApproverType::RequesterManager, null, 'Manager review']]);
        $resolution = app(WorkflowResolver::class)->resolve($this->context($requester));

        $this->assertStartupFailsWithoutPartialRuntime($approvable, $resolution);
    }

    public function test_missing_approver_failure_rolls_back_all_runtime_records(): void
    {
        $approvable = Department::factory()->create();
        $requester = User::factory()->create(['manager_id' => null]);
        $this->publishedRoute([[ApproverType::RequesterManager, null, 'Manager review']]);
        $resolution = app(WorkflowResolver::class)->resolve($this->context($requester));

        $this->assertStartupFailsWithoutPartialRuntime($approvable, $resolution);
    }

    public function test_archived_or_mismatched_resolution_fails_without_runtime_records(): void
    {
        $manager = User::factory()->create();
        $approvable = Department::factory()->create();
        $requester = User::factory()->create(['manager_id' => $manager]);
        [$version] = $this->publishedRoute([[ApproverType::RequesterManager, null, 'Manager review']]);
        $resolution = app(WorkflowResolver::class)->resolve($this->context($requester));
        DB::table('workflow_versions')->where('id', $version->id)->update(['status' => WorkflowVersionStatus::Archived->value]);

        $this->expectException(ApprovalRuntimeException::class);

        try {
            app(WorkflowEngine::class)->start($approvable, $resolution);
        } finally {
            $this->assertDatabaseCount('approval_instances', 0);
        }
    }

    public function test_runtime_history_cannot_be_hard_deleted(): void
    {
        $manager = User::factory()->create();
        $approvable = Department::factory()->create();
        $requester = User::factory()->create(['manager_id' => $manager]);
        $this->publishedRoute([[ApproverType::RequesterManager, null, 'Manager review']]);
        $resolution = app(WorkflowResolver::class)->resolve($this->context($requester));
        $instance = app(WorkflowEngine::class)->start($approvable, $resolution);

        try {
            $instance->delete();
            $this->fail('Approval runtime history must not be deletable.');
        } catch (LogicException) {
            $this->assertModelExists($instance);
        }

        try {
            $instance->actions->first()->update(['comment' => 'rewritten']);
            $this->fail('Approval action history must be append-only.');
        } catch (LogicException) {
            $this->assertNull($instance->actions->first()->fresh()->comment);
        }
    }

    private function assertStartupFailsWithoutPartialRuntime(Department $approvable, WorkflowResolution $resolution): void
    {
        try {
            app(WorkflowEngine::class)->start($approvable, $resolution);
            $this->fail('Workflow startup should fail when its first approver is unavailable.');
        } catch (ApprovalRuntimeException $exception) {
            $this->assertSame('A required approver is unavailable. Please contact an administrator.', $exception->getMessage());
        }

        $this->assertDatabaseCount('approval_instances', 0);
        $this->assertDatabaseCount('approval_step_instances', 0);
        $this->assertDatabaseCount('approval_assignments', 0);
        $this->assertDatabaseCount('approval_actions', 0);
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

    /**
     * @param  list<array{ApproverType, ?string, string}>  $steps
     * @return array{WorkflowVersion, WorkflowRuleGroup}
     */
    private function publishedRoute(array $steps): array
    {
        $template = WorkflowTemplate::factory()->create();
        $version = WorkflowVersion::factory()->create(['workflow_template_id' => $template]);
        $group = $version->ruleGroups()->create([
            'name' => 'Default route',
            'priority' => 10,
            'is_default' => true,
        ]);

        foreach ($steps as $index => [$approverType, $approverValue, $name]) {
            $group->steps()->create([
                'step_order' => $index + 1,
                'name' => $name,
                'approver_type' => $approverType,
                'approver_value' => $approverValue,
                'approval_mode' => ApprovalMode::Any,
                'minimum_approvals' => null,
                'sla_hours' => null,
            ]);
        }

        DB::table('workflow_versions')->where('id', $version->id)->update([
            'status' => WorkflowVersionStatus::Published->value,
        ]);

        return [$version, $group];
    }
}
