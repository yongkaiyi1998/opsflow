<?php

namespace Tests\Feature;

use App\ApprovalActionType;
use App\ApprovalAssignmentStatus;
use App\ApprovalInstanceStatus;
use App\ApprovalMode;
use App\ApprovalStepStatus;
use App\ApproverType;
use App\Models\ApprovalInstance;
use App\Models\Department;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestItem;
use App\Models\User;
use App\Models\WorkflowTemplate;
use App\Models\WorkflowVersion;
use App\PurchaseRequestStatus;
use App\Services\ApprovalService;
use App\Services\WorkflowEngine;
use App\Services\WorkflowResolver;
use App\UserRole;
use App\WorkflowContext;
use App\WorkflowModuleType;
use App\WorkflowVersionStatus;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ApprovalOperationsTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_inbox_uses_current_users_persisted_pending_assignments_and_detail_shows_business_data(): void
    {
        $firstApprover = User::factory()->create(['role' => UserRole::Finance]);
        $otherApprover = User::factory()->create(['role' => UserRole::Finance]);
        [$instance, $business] = $this->runtime([[ApproverType::Role, UserRole::Finance->value, 'Finance review']]);
        $business->attachments()->create([
            'original_name' => 'quotation.pdf',
            'stored_name' => 'stored.pdf',
            'disk' => 'local',
            'path' => 'attachments/stored.pdf',
            'mime_type' => 'application/pdf',
            'size' => 100,
            'uploaded_by' => $instance->context()->requesterId,
        ]);
        $assignment = $instance->steps->first()->assignments->firstWhere('approver_id', $firstApprover->id);

        $this->actingAs($firstApprover)->get(route('approvals.index'))
            ->assertOk()
            ->assertViewHas('assignments', fn ($assignments): bool => $assignments->count() === 1
                && $assignments->first()->approver_id === $firstApprover->id)
            ->assertSee($business->request_no)
            ->assertSee('Finance review');
        $this->actingAs($otherApprover)->get(route('approvals.index'))
            ->assertOk()
            ->assertViewHas('assignments', fn ($assignments): bool => $assignments->count() === 1
                && $assignments->first()->approver_id === $otherApprover->id);
        $this->actingAs($firstApprover)->get(route('approvals.show', $assignment))
            ->assertOk()
            ->assertSee('Secure laptop')
            ->assertSee('quotation.pdf')
            ->assertSee('Submit')
            ->assertSee('Approve')
            ->assertSee('Request changes');

        DB::table('purchase_requests')->where('id', $business->id)->update([
            'status' => PurchaseRequestStatus::ChangesRequested->value,
        ]);
        $this->actingAs($firstApprover)->get(route('approvals.index'))
            ->assertOk()
            ->assertViewHas('assignments', fn ($assignments): bool => $assignments->isEmpty());
    }

    public function test_unassigned_users_waiting_assignments_and_self_assignments_cannot_act(): void
    {
        $approver = User::factory()->create();
        [$instance] = $this->runtime([
            [ApproverType::SpecificUser, (string) $approver->id, 'Manager review'],
            [ApproverType::SpecificUser, (string) User::factory()->create()->id, 'Finance review'],
        ]);
        $activeAssignment = $instance->steps->first()->assignments->sole();
        $unassigned = User::factory()->create();

        $this->actingAs($unassigned)->post(route('approval-assignments.approve', $activeAssignment))->assertForbidden();

        $waitingAssignment = $instance->steps->last()->assignments()->create([
            'approver_id' => $approver->id,
            'status' => ApprovalAssignmentStatus::Pending,
            'assigned_at' => now(),
        ]);
        $this->actingAs($approver)
            ->from(route('approvals.show', $waitingAssignment))
            ->post(route('approval-assignments.approve', $waitingAssignment))
            ->assertRedirect(route('approvals.show', $waitingAssignment))
            ->assertSessionHasErrors('action');

        $requester = $instance->approvable->requester;
        $selfAssignment = $instance->steps->first()->assignments()->create([
            'approver_id' => $requester->id,
            'status' => ApprovalAssignmentStatus::Pending,
            'assigned_at' => now(),
        ]);
        $this->actingAs($requester)->post(route('approval-assignments.approve', $selfAssignment))->assertForbidden();
        $this->assertSame(ApprovalAssignmentStatus::Pending, $activeAssignment->fresh()->status);
        $this->assertDatabaseCount('approval_actions', 1);
    }

    public function test_any_approval_skips_siblings_and_activates_only_the_next_step_with_fresh_assignments(): void
    {
        $actingApprover = User::factory()->create(['role' => UserRole::Finance]);
        $siblingApprover = User::factory()->create(['role' => UserRole::Finance]);
        $nextApprover = User::factory()->create();
        [$instance, $business] = $this->runtime([
            [ApproverType::Role, UserRole::Finance->value, 'Finance review'],
            [ApproverType::SpecificUser, (string) $nextApprover->id, 'Director review'],
        ]);
        $firstStep = $instance->steps->first();
        $assignment = $firstStep->assignments->firstWhere('approver_id', $actingApprover->id);

        $this->actingAs($actingApprover)->post(
            route('approval-assignments.approve', $assignment),
            ['comment' => 'Approved within budget.'],
        )->assertRedirect(route('approvals.index'));

        $instance->refresh()->load(['steps.assignments', 'actions']);
        $this->assertSame(ApprovalAssignmentStatus::Approved, $assignment->fresh()->status);
        $this->assertSame(ApprovalAssignmentStatus::Skipped, $firstStep->assignments->firstWhere('approver_id', $siblingApprover->id)->fresh()->status);
        $this->assertSame(ApprovalStepStatus::Completed, $instance->steps->first()->status);
        $this->assertSame(ApprovalStepStatus::Active, $instance->steps->last()->status);
        $this->assertSame($nextApprover->id, $instance->steps->last()->assignments->sole()->approver_id);
        $this->assertSame(ApprovalAssignmentStatus::Pending, $instance->steps->last()->assignments->sole()->status);
        $this->assertSame(ApprovalInstanceStatus::InProgress, $instance->status);
        $this->assertSame(2, $instance->current_step_order);
        $this->assertSame(PurchaseRequestStatus::InApproval, $business->fresh()->status);
        $this->assertSame([ApprovalActionType::Submitted, ApprovalActionType::Approved], $instance->actions->pluck('action')->all());
    }

    public function test_final_approval_completes_runtime_and_business_record_consistently(): void
    {
        $approver = User::factory()->create();
        [$instance, $business] = $this->runtime([[ApproverType::SpecificUser, (string) $approver->id, 'Final review']]);
        $assignment = $instance->steps->sole()->assignments->sole();

        $this->actingAs($approver)->post(route('approval-assignments.approve', $assignment))->assertRedirect(route('approvals.index'));

        $instance->refresh();
        $business->refresh();
        $this->assertSame(ApprovalAssignmentStatus::Approved, $assignment->fresh()->status);
        $this->assertNotNull($assignment->fresh()->acted_at);
        $this->assertSame(ApprovalStepStatus::Completed, $instance->steps()->sole()->status);
        $this->assertSame(ApprovalInstanceStatus::Approved, $instance->status);
        $this->assertNull($instance->current_step_order);
        $this->assertNotNull($instance->completed_at);
        $this->assertSame(PurchaseRequestStatus::Approved, $business->status);
        $this->assertNotNull($business->approved_at);
        $this->assertSame(2, $business->lock_version);
        $this->assertDatabaseHas('approval_actions', [
            'approval_instance_id' => $instance->id,
            'approval_step_instance_id' => $instance->steps()->sole()->id,
            'actor_id' => $approver->id,
            'action' => ApprovalActionType::Approved->value,
        ]);
    }

    public function test_rejection_terminates_runtime_skips_siblings_and_cancels_future_steps(): void
    {
        $rejectingApprover = User::factory()->create(['role' => UserRole::Finance]);
        $siblingApprover = User::factory()->create(['role' => UserRole::Finance]);
        $futureApprover = User::factory()->create();
        [$instance, $business] = $this->runtime([
            [ApproverType::Role, UserRole::Finance->value, 'Finance review'],
            [ApproverType::SpecificUser, (string) $futureApprover->id, 'Director review'],
        ]);
        $assignment = $instance->steps->first()->assignments->firstWhere('approver_id', $rejectingApprover->id);

        $this->actingAs($rejectingApprover)->post(
            route('approval-assignments.reject', $assignment),
            ['comment' => '  '],
        )->assertSessionHasErrors('comment');
        $this->assertSame(ApprovalAssignmentStatus::Pending, $assignment->fresh()->status);

        $this->actingAs($rejectingApprover)->post(
            route('approval-assignments.reject', $assignment),
            ['comment' => 'Budget is unavailable.'],
        )->assertRedirect(route('approvals.index'));

        $instance->refresh()->load(['steps.assignments', 'actions']);
        $this->assertSame(ApprovalAssignmentStatus::Rejected, $assignment->fresh()->status);
        $this->assertSame(ApprovalAssignmentStatus::Skipped, $instance->steps->first()->assignments->firstWhere('approver_id', $siblingApprover->id)->status);
        $this->assertSame(ApprovalStepStatus::Rejected, $instance->steps->first()->status);
        $this->assertSame(ApprovalStepStatus::Cancelled, $instance->steps->last()->status);
        $this->assertSame(ApprovalInstanceStatus::Rejected, $instance->status);
        $this->assertNull($instance->current_step_order);
        $this->assertSame(PurchaseRequestStatus::Rejected, $business->fresh()->status);
        $this->assertNotNull($business->fresh()->rejected_at);
        $this->assertSame('Budget is unavailable.', $instance->actions->last()->comment);
    }

    public function test_request_changes_requires_comment_and_pauses_runtime_without_deleting_history(): void
    {
        $actingApprover = User::factory()->create(['role' => UserRole::Finance]);
        $siblingApprover = User::factory()->create(['role' => UserRole::Finance]);
        [$instance, $business] = $this->runtime([[ApproverType::Role, UserRole::Finance->value, 'Finance review']]);
        $assignment = $instance->steps->sole()->assignments->firstWhere('approver_id', $actingApprover->id);

        $this->actingAs($actingApprover)->post(
            route('approval-assignments.request-changes', $assignment),
            ['comment' => '   '],
        )->assertSessionHasErrors('comment');
        $this->actingAs($actingApprover)->post(
            route('approval-assignments.request-changes', $assignment),
            ['comment' => 'Attach the missing quotation.'],
        )->assertRedirect(route('approvals.index'));

        $instance->refresh()->load(['steps.assignments', 'actions']);
        $this->assertSame(ApprovalAssignmentStatus::Cancelled, $assignment->fresh()->status);
        $this->assertNotNull($assignment->fresh()->acted_at);
        $this->assertSame(ApprovalAssignmentStatus::Cancelled, $instance->steps->sole()->assignments->firstWhere('approver_id', $siblingApprover->id)->status);
        $this->assertSame(ApprovalStepStatus::Active, $instance->steps->sole()->status);
        $this->assertSame(ApprovalInstanceStatus::InProgress, $instance->status);
        $this->assertSame(1, $instance->current_step_order);
        $this->assertSame(PurchaseRequestStatus::ChangesRequested, $business->fresh()->status);
        $this->assertSame([ApprovalActionType::Submitted, ApprovalActionType::ChangesRequested], $instance->actions->pluck('action')->all());
        $this->assertSame('Attach the missing quotation.', $instance->actions->last()->comment);
        $this->actingAs($siblingApprover)->get(route('approvals.index'))->assertDontSee($business->request_no);
    }

    public function test_repeated_and_competing_actions_fail_without_duplicate_history_or_state_changes(): void
    {
        $firstApprover = User::factory()->create(['role' => UserRole::Finance]);
        $secondApprover = User::factory()->create(['role' => UserRole::Finance]);
        [$instance, $business] = $this->runtime([[ApproverType::Role, UserRole::Finance->value, 'Finance review']]);
        $firstAssignment = $instance->steps->sole()->assignments->firstWhere('approver_id', $firstApprover->id);
        $secondAssignment = $instance->steps->sole()->assignments->firstWhere('approver_id', $secondApprover->id);

        $this->actingAs($firstApprover)->post(route('approval-assignments.approve', $firstAssignment))->assertRedirect();
        $this->actingAs($firstApprover)
            ->from(route('approvals.show', $firstAssignment))
            ->post(route('approval-assignments.approve', $firstAssignment))
            ->assertSessionHasErrors('action');
        $this->actingAs($secondApprover)
            ->from(route('approvals.show', $secondAssignment))
            ->post(route('approval-assignments.reject', $secondAssignment), ['comment' => 'Too late.'])
            ->assertSessionHasErrors('action');

        $this->assertSame(PurchaseRequestStatus::Approved, $business->fresh()->status);
        $this->assertSame(ApprovalInstanceStatus::Approved, $instance->fresh()->status);
        $this->assertDatabaseCount('approval_actions', 2);
        $this->assertDatabaseCount('approval_instances', 1);
    }

    public function test_inactive_approver_cannot_act_and_authoritative_state_is_unchanged(): void
    {
        $approver = User::factory()->create();
        [$instance, $business] = $this->runtime([[ApproverType::SpecificUser, (string) $approver->id, 'Manager review']]);
        $assignment = $instance->steps->sole()->assignments->sole();
        $approver->forceFill(['status' => 'INACTIVE'])->save();

        try {
            app(ApprovalService::class)->approve($assignment, $approver);
            $this->fail('An inactive approver must not be able to act.');
        } catch (AuthorizationException) {
            $this->assertSame(ApprovalAssignmentStatus::Pending, $assignment->fresh()->status);
            $this->assertSame(ApprovalStepStatus::Active, $instance->steps()->sole()->status);
            $this->assertSame(ApprovalInstanceStatus::InProgress, $instance->fresh()->status);
            $this->assertSame(PurchaseRequestStatus::InApproval, $business->fresh()->status);
            $this->assertDatabaseCount('approval_actions', 1);
        }
    }

    public function test_next_approver_failure_rolls_back_the_entire_approval_transition(): void
    {
        $approver = User::factory()->create();
        [$instance, $business] = $this->runtime([
            [ApproverType::SpecificUser, (string) $approver->id, 'Manager review'],
            [ApproverType::SpecificUser, '999999', 'Unavailable review'],
        ]);
        $assignment = $instance->steps->first()->assignments->sole();

        $this->actingAs($approver)
            ->from(route('approvals.show', $assignment))
            ->post(route('approval-assignments.approve', $assignment))
            ->assertSessionHasErrors('workflow');

        $instance->refresh()->load('steps.assignments');
        $this->assertSame(ApprovalAssignmentStatus::Pending, $assignment->fresh()->status);
        $this->assertNull($assignment->fresh()->acted_at);
        $this->assertSame(ApprovalStepStatus::Active, $instance->steps->first()->status);
        $this->assertSame(ApprovalStepStatus::Waiting, $instance->steps->last()->status);
        $this->assertCount(0, $instance->steps->last()->assignments);
        $this->assertSame(ApprovalInstanceStatus::InProgress, $instance->status);
        $this->assertSame(1, $instance->current_step_order);
        $this->assertSame(PurchaseRequestStatus::InApproval, $business->fresh()->status);
        $this->assertDatabaseCount('approval_actions', 1);
    }

    /**
     * @param  list<array{ApproverType, ?string, string}>  $steps
     * @return array{ApprovalInstance, PurchaseRequest}
     */
    private function runtime(array $steps): array
    {
        $department = Department::factory()->create();
        $requester = User::factory()->create(['department_id' => $department]);
        $business = PurchaseRequest::factory()->create([
            'request_no' => fake()->unique()->numerify('PR-2026-8#####'),
            'requester_id' => $requester,
            'department_id' => $department,
            'status' => PurchaseRequestStatus::Draft,
            'lock_version' => 1,
        ]);
        PurchaseRequestItem::factory()->create([
            'purchase_request_id' => $business,
            'description' => 'Secure laptop',
        ]);
        DB::table('purchase_requests')->where('id', $business->id)->update([
            'status' => PurchaseRequestStatus::InApproval->value,
        ]);
        $business->refresh();
        $template = WorkflowTemplate::factory()->create(['module_type' => WorkflowModuleType::PurchaseRequest]);
        $version = WorkflowVersion::factory()->create(['workflow_template_id' => $template]);
        $group = $version->ruleGroups()->create([
            'name' => 'Approval operations route',
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
        $context = WorkflowContext::fromValues(
            WorkflowModuleType::PurchaseRequest,
            $requester->id,
            $department->id,
            $business->category_id,
            $business->total_amount,
            $business->currency,
        );
        $resolution = app(WorkflowResolver::class)->resolve($context);
        $instance = app(WorkflowEngine::class)->start($business, $resolution);

        return [$instance, $business];
    }
}
