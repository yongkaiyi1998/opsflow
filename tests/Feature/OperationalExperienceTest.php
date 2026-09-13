<?php

namespace Tests\Feature;

use App\ApprovalAssignmentStatus;
use App\ApprovalInstanceStatus;
use App\ApprovalMode;
use App\ApproverType;
use App\Models\ActivityLog;
use App\Models\ApprovalInstance;
use App\Models\Department;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestItem;
use App\Models\SpendCategory;
use App\Models\SupplierInvoice;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkflowTemplate;
use App\Models\WorkflowVersion;
use App\Notifications\ApprovalAssignedNotification;
use App\Notifications\ChangesRequestedNotification;
use App\Notifications\RequestApprovedNotification;
use App\Notifications\RequestRejectedNotification;
use App\Notifications\RequestResubmittedNotification;
use App\PurchaseRequestStatus;
use App\Services\ApprovalService;
use App\Services\WorkflowEngine;
use App\Services\WorkflowResolver;
use App\SupplierInvoiceStatus;
use App\UserRole;
use App\WorkflowContext;
use App\WorkflowModuleType;
use App\WorkflowResolution;
use App\WorkflowVersionStatus;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Notifications\Channels\DatabaseChannel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

class OperationalExperienceTest extends TestCase
{
    use DatabaseTruncation;

    protected function beforeTruncatingDatabase(): void
    {
        RefreshDatabaseState::$migrated = false;
        RefreshDatabaseState::$inMemoryConnections = [];
    }

    protected function tearDown(): void
    {
        RefreshDatabaseState::$migrated = false;
        RefreshDatabaseState::$inMemoryConnections = [];

        parent::tearDown();
    }

    public function test_assignments_and_terminal_actions_notify_the_correct_users_once_after_commit(): void
    {
        Notification::fake();
        $firstApprover = User::factory()->create();
        $secondApprover = User::factory()->create();
        [$instance, $business] = $this->runtime([
            [ApproverType::SpecificUser, (string) $firstApprover->id, 'Manager review'],
            [ApproverType::SpecificUser, (string) $secondApprover->id, 'Finance review'],
        ]);

        Notification::assertSentTo($firstApprover, ApprovalAssignedNotification::class, function ($notification) use ($business): bool {
            return $notification->reference === $business->request_no
                && $notification->targetUrl === route('approvals.show', $business->approvalInstances()->first()->steps()->first()->assignments()->first(), false);
        });
        Notification::assertNotSentTo($secondApprover, ApprovalAssignedNotification::class);

        $firstAssignment = $instance->steps->first()->assignments->sole();
        app(ApprovalService::class)->approve($firstAssignment, $firstApprover, 'Manager approved.');

        Notification::assertSentTo($secondApprover, ApprovalAssignedNotification::class);
        Notification::assertNotSentTo($business->requester, RequestApprovedNotification::class);

        $secondAssignment = $instance->steps()->where('step_order', 2)->firstOrFail()->assignments()->sole();
        app(ApprovalService::class)->approve($secondAssignment, $secondApprover, 'Final approval.');

        Notification::assertSentTo($business->requester, RequestApprovedNotification::class);
        $this->assertSame(PurchaseRequestStatus::Approved, $business->fresh()->status);

        try {
            app(ApprovalService::class)->approve($secondAssignment, $secondApprover);
            $this->fail('A stale approval must fail.');
        } catch (ValidationException) {
            $this->assertCount(1, Notification::sent($business->requester, RequestApprovedNotification::class));
        }
    }

    public function test_changes_rejection_and_resubmission_notifications_follow_authoritative_transitions(): void
    {
        Notification::fake();
        $approver = User::factory()->create();
        [$instance, $business] = $this->runtime([
            [ApproverType::SpecificUser, (string) $approver->id, 'Manager review'],
        ]);
        $assignment = $instance->steps->sole()->assignments->sole();

        app(ApprovalService::class)->requestChanges($assignment, $approver, 'Attach a signed quotation.');

        Notification::assertSentTo($business->requester, ChangesRequestedNotification::class, fn ($notification): bool => str_contains($notification->message, 'Attach a signed quotation.'));
        app(ApprovalService::class)->resubmit($business->fresh(), $business->requester, $instance->context(), 'Document attached.');
        Notification::assertSentTo($approver, RequestResubmittedNotification::class);

        $freshAssignment = $instance->steps->sole()->assignments()->where('status', ApprovalAssignmentStatus::Pending)->sole();
        app(ApprovalService::class)->reject($freshAssignment, $approver, 'Budget is unavailable.');

        Notification::assertSentTo($business->requester, RequestRejectedNotification::class, fn ($notification): bool => str_contains($notification->message, 'Budget is unavailable.'));
        $this->assertCount(1, Notification::sent($approver, RequestResubmittedNotification::class));
    }

    public function test_rolled_back_workflow_does_not_notify_and_notification_failure_does_not_revert_state(): void
    {
        $rollbackApprover = User::factory()->create();
        [$business, $resolution] = $this->runtimeDefinition([
            [ApproverType::SpecificUser, (string) $rollbackApprover->id, 'Review'],
        ]);

        try {
            DB::transaction(function () use ($business, $resolution): void {
                app(WorkflowEngine::class)->start($business, $resolution);
                throw new RuntimeException('Force rollback.');
            });
        } catch (RuntimeException) {
            $this->assertDatabaseCount('notifications', 0);
            $this->assertDatabaseCount('approval_instances', 0);
        }

        $this->mock(DatabaseChannel::class, function ($mock): void {
            $mock->shouldReceive('send')->andThrow(new RuntimeException('Notification storage unavailable.'));
        });
        $instance = app(WorkflowEngine::class)->start($business, $resolution);

        app(ApprovalService::class)->approve($instance->steps->sole()->assignments->sole(), $rollbackApprover);

        $this->assertSame(PurchaseRequestStatus::Approved, $business->fresh()->status);
        $this->assertSame(ApprovalInstanceStatus::Approved, $instance->fresh()->status);
    }

    public function test_notification_center_is_scoped_and_read_status_does_not_change_business_state(): void
    {
        $owner = User::factory()->create(['department_id' => Department::factory()]);
        $otherUser = User::factory()->create();
        $business = PurchaseRequest::factory()->create([
            'requester_id' => $owner,
            'department_id' => $owner->department_id,
            'status' => PurchaseRequestStatus::InApproval,
        ]);
        $owner->notify(new RequestApprovedNotification(
            $business->request_no,
            "Purchase Request {$business->request_no} has been approved.",
            route('purchase-requests.show', $business, false),
        ));
        $notification = $owner->notifications()->sole();

        $this->actingAs($owner)->get(route('notifications.index'))
            ->assertOk()
            ->assertSee($business->request_no)
            ->assertSee(route('purchase-requests.show', $business, false));
        $this->actingAs($otherUser)->get(route('notifications.index'))->assertDontSee($business->request_no);
        $this->actingAs($otherUser)->post(route('notifications.read', $notification->id))->assertNotFound();
        $this->actingAs($otherUser)->get($notification->data['target_url'])->assertForbidden();

        $this->actingAs($owner)->post(route('notifications.read', $notification->id))->assertRedirect();

        $this->assertNotNull($notification->fresh()->read_at);
        $this->assertSame(PurchaseRequestStatus::InApproval, $business->fresh()->status);
    }

    public function test_admin_and_important_business_changes_create_explicit_activity_logs(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $vendor = Vendor::factory()->create(['name' => 'Old Vendor']);
        $department = Department::factory()->create(['name' => 'Old Department']);
        $category = SpendCategory::factory()->create(['name' => 'Old Category']);
        $targetUser = User::factory()->create(['role' => UserRole::Employee]);

        $this->actingAs($admin)->put(route('vendors.update', $vendor), [
            'name' => 'Updated Vendor',
            'code' => $vendor->code,
            'email' => $vendor->email,
            'phone' => $vendor->phone,
            'status' => $vendor->status->value,
        ])->assertRedirect(route('vendors.index'));
        $this->actingAs($admin)->put(route('departments.update', $department), [
            'name' => 'Updated Department',
            'code' => $department->code,
            'manager_id' => null,
            'status' => $department->status->value,
        ])->assertRedirect(route('departments.index'));
        $this->actingAs($admin)->put(route('spend-categories.update', $category), [
            'name' => 'Updated Category',
            'code' => $category->code,
            'status' => $category->status->value,
        ])->assertRedirect(route('spend-categories.index'));
        $this->actingAs($admin)->put(route('users.update', $targetUser), [
            'name' => $targetUser->name,
            'email' => $targetUser->email,
            'role' => UserRole::Finance->value,
            'status' => $targetUser->status->value,
            'department_id' => $targetUser->department_id,
            'manager_id' => null,
        ])->assertRedirect(route('users.index'));

        $this->assertDatabaseHas('activity_logs', ['subject_type' => $vendor->getMorphClass(), 'subject_id' => $vendor->id, 'action' => 'VENDOR_UPDATED', 'user_id' => $admin->id]);
        $this->assertDatabaseHas('activity_logs', ['subject_type' => $department->getMorphClass(), 'subject_id' => $department->id, 'action' => 'DEPARTMENT_UPDATED', 'user_id' => $admin->id]);
        $this->assertDatabaseHas('activity_logs', ['subject_type' => $category->getMorphClass(), 'subject_id' => $category->id, 'action' => 'SPEND_CATEGORY_UPDATED', 'user_id' => $admin->id]);
        $this->assertDatabaseHas('activity_logs', ['subject_type' => $targetUser->getMorphClass(), 'subject_id' => $targetUser->id, 'action' => 'USER_ROLE_CHANGED', 'user_id' => $admin->id]);

        $requester = User::factory()->create(['department_id' => Department::factory()]);
        $requestCategory = SpendCategory::factory()->create();
        $purchaseRequest = PurchaseRequest::factory()->create([
            'requester_id' => $requester,
            'department_id' => $requester->department_id,
            'category_id' => $requestCategory,
            'lock_version' => 1,
        ]);
        PurchaseRequestItem::factory()->create(['purchase_request_id' => $purchaseRequest]);

        $this->actingAs($requester)->put(route('purchase-requests.update', $purchaseRequest), [
            'title' => 'Updated equipment request',
            'description' => 'Updated business justification.',
            'category_id' => $requestCategory->id,
            'vendor_id' => null,
            'needed_by_date' => now()->addMonth()->toDateString(),
            'tax_amount' => '0.00',
            'lock_version' => 1,
            'items' => [['description' => 'Laptop', 'quantity' => 2, 'unit_price' => '60.00']],
        ])->assertRedirect(route('purchase-requests.show', $purchaseRequest));

        $log = ActivityLog::query()
            ->whereMorphedTo('subject', $purchaseRequest)
            ->where('action', 'PURCHASE_REQUEST_UPDATED')
            ->sole();
        $this->assertSame('100.00', $log->old_values['total_amount']);
        $this->assertSame('120.00', $log->new_values['total_amount']);
        $this->assertArrayNotHasKey('description', $log->old_values);
        $this->assertArrayNotHasKey('description', $log->new_values);
        $this->assertArrayNotHasKey('description', $log->new_values['items'][0]);
        $this->assertSame($requester->id, $log->user_id);
    }

    public function test_dashboard_is_role_aware_and_pending_work_is_query_backed(): void
    {
        $employee = User::factory()->create(['department_id' => Department::factory()]);
        PurchaseRequest::factory()->create(['requester_id' => $employee, 'department_id' => $employee->department_id, 'status' => PurchaseRequestStatus::Draft]);
        PurchaseRequest::factory()->create(['requester_id' => $employee, 'department_id' => $employee->department_id, 'status' => PurchaseRequestStatus::Approved, 'approved_at' => now()]);
        PurchaseRequest::factory()->create(['status' => PurchaseRequestStatus::Draft]);
        [$instance] = $this->runtime([[ApproverType::SpecificUser, (string) $employee->id, 'Employee review']]);

        $this->actingAs($employee)->get(route('dashboard'))
            ->assertOk()
            ->assertViewHas('summary', fn (array $summary): bool => $summary['drafts'] === 1 && $summary['waiting'] === 0)
            ->assertViewHas('approver', fn (array $approver): bool => $approver['pending_count'] === 1)
            ->assertSee('My approval inbox')
            ->assertDontSee('Finance operations')
            ->assertDontSee('Administration');

        DB::table('approval_assignments')->where('id', $instance->steps->sole()->assignments->sole()->id)->update(['status' => ApprovalAssignmentStatus::Cancelled->value]);
        $this->actingAs($employee)->get(route('dashboard'))
            ->assertViewHas('approver', fn (array $approver): bool => $approver['pending_count'] === 0);

        $finance = User::factory()->create(['role' => UserRole::Finance]);
        SupplierInvoice::factory()->create(['status' => SupplierInvoiceStatus::InApproval]);
        SupplierInvoice::factory()->create(['status' => SupplierInvoiceStatus::Approved, 'due_date' => today()->addDays(3)]);
        $this->actingAs($finance)->get(route('dashboard'))->assertOk()->assertSee('Finance operations');
        $assignment = $instance->steps->sole()->assignments->sole();
        $this->actingAs($finance)->post(route('approval-assignments.approve', $assignment))->assertForbidden();

        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin)->get(route('dashboard'))->assertOk()->assertSee('Administration')->assertSee('Active users');
        $this->actingAs($admin)->post(route('approval-assignments.approve', $assignment))->assertForbidden();
    }

    public function test_authorized_business_view_renders_complete_approval_action_history(): void
    {
        $approver = User::factory()->create(['name' => 'Jordan Approver']);
        [$instance, $business] = $this->runtime([
            [ApproverType::SpecificUser, (string) $approver->id, 'Final review'],
        ]);
        app(ApprovalService::class)->approve($instance->steps->sole()->assignments->sole(), $approver, 'Approved for operations.');

        $this->actingAs($business->requester)->get(route('purchase-requests.show', $business))
            ->assertOk()
            ->assertSee('Approval timeline')
            ->assertSee('Submitted')
            ->assertSee('Approved')
            ->assertSee('Jordan Approver')
            ->assertSee('Final review')
            ->assertSee('Approved for operations.');
    }

    /**
     * @param  list<array{ApproverType, ?string, string}>  $steps
     * @return array{ApprovalInstance, PurchaseRequest}
     */
    private function runtime(array $steps): array
    {
        [$business, $resolution] = $this->runtimeDefinition($steps);
        $instance = app(WorkflowEngine::class)->start($business, $resolution);

        return [$instance, $business];
    }

    /**
     * @param  list<array{ApproverType, ?string, string}>  $steps
     * @return array{PurchaseRequest, WorkflowResolution}
     */
    private function runtimeDefinition(array $steps): array
    {
        $department = Department::factory()->create();
        $requester = User::factory()->create(['department_id' => $department]);
        $business = PurchaseRequest::factory()->create([
            'requester_id' => $requester,
            'department_id' => $department,
            'status' => PurchaseRequestStatus::Draft,
        ]);
        PurchaseRequestItem::factory()->create(['purchase_request_id' => $business]);
        DB::table('purchase_requests')->where('id', $business->id)->update([
            'status' => PurchaseRequestStatus::InApproval->value,
        ]);
        $business->refresh();
        $template = WorkflowTemplate::factory()->create(['module_type' => WorkflowModuleType::PurchaseRequest]);
        $version = WorkflowVersion::factory()->create(['workflow_template_id' => $template]);
        $group = $version->ruleGroups()->create(['name' => 'Operations route', 'priority' => 10, 'is_default' => true]);

        foreach ($steps as $index => [$type, $value, $name]) {
            $group->steps()->create([
                'step_order' => $index + 1,
                'name' => $name,
                'approver_type' => $type,
                'approver_value' => $value,
                'approval_mode' => ApprovalMode::Any,
            ]);
        }

        DB::table('workflow_versions')->where('id', $version->id)->update(['status' => WorkflowVersionStatus::Published->value]);
        $context = WorkflowContext::fromValues(
            WorkflowModuleType::PurchaseRequest,
            $requester->id,
            $department->id,
            $business->category_id,
            $business->total_amount,
            $business->currency,
        );

        return [$business, app(WorkflowResolver::class)->resolve($context)];
    }
}
