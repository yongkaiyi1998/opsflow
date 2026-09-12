<?php

namespace Tests\Feature;

use App\ApprovalActionType;
use App\ApprovalAssignmentStatus;
use App\ApprovalInstanceStatus;
use App\ApprovalMode;
use App\ApprovalStepStatus;
use App\ApproverType;
use App\ExpenseClaimStatus;
use App\Models\ApprovalInstance;
use App\Models\Department;
use App\Models\ExpenseClaim;
use App\Models\ExpenseItem;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestItem;
use App\Models\SpendCategory;
use App\Models\SupplierInvoice;
use App\Models\SupplierInvoiceItem;
use App\Models\User;
use App\Models\WorkflowRuleGroup;
use App\Models\WorkflowTemplate;
use App\Models\WorkflowVersion;
use App\PurchaseRequestStatus;
use App\Services\ApprovalService;
use App\Services\PurchaseRequestService;
use App\Services\WorkflowEngine;
use App\Services\WorkflowResolver;
use App\SupplierInvoiceStatus;
use App\UserRole;
use App\WorkflowContext;
use App\WorkflowModuleType;
use App\WorkflowRuleField;
use App\WorkflowRuleOperator;
use App\WorkflowVersionStatus;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ResubmissionWithdrawalTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_non_routing_resubmission_keeps_current_runtime_and_prior_approvals_with_a_fresh_assignment(): void
    {
        $manager = User::factory()->create();
        $finance = User::factory()->create();
        [$instance, $request, $requester] = $this->purchaseRuntime([
            [ApproverType::SpecificUser, (string) $manager->id, 'Manager review'],
            [ApproverType::SpecificUser, (string) $finance->id, 'Finance review'],
        ]);

        app(ApprovalService::class)->approve($instance->steps->first()->assignments->sole(), $manager);
        $instance->refresh()->load('steps.assignments');
        $firstStep = $instance->steps->first();
        $currentStep = $instance->steps->last();
        $oldAssignment = $currentStep->assignments->sole();
        app(ApprovalService::class)->requestChanges($oldAssignment, $finance, 'Clarify the business need.');

        $this->actingAs($requester)->get(route('purchase-requests.show', $request))
            ->assertOk()
            ->assertSee('Clarify the business need.')
            ->assertSee('Resubmit for approval')
            ->assertSee('Edit')
            ->assertSee('Withdraw');

        $request->refresh()->load('items');
        app(PurchaseRequestService::class)->update(
            $request,
            $this->purchasePayload($request, ['description' => 'Clarified business need without changing the route.']),
            $requester,
        );

        $this->actingAs($requester)->post(route('purchase-requests.resubmit', $request), [
            'comment' => 'The description has been clarified.',
        ])->assertRedirect(route('purchase-requests.show', $request));

        $instance->refresh()->load(['steps.assignments', 'actions']);
        $this->assertDatabaseCount('approval_instances', 1);
        $this->assertSame(ApprovalInstanceStatus::InProgress, $instance->status);
        $this->assertSame(2, $instance->current_step_order);
        $this->assertSame(ApprovalStepStatus::Completed, $firstStep->fresh()->status);
        $this->assertSame(ApprovalAssignmentStatus::Approved, $firstStep->assignments->sole()->fresh()->status);
        $this->assertSame(ApprovalStepStatus::Active, $currentStep->fresh()->status);
        $this->assertSame(ApprovalAssignmentStatus::Cancelled, $oldAssignment->fresh()->status);
        $this->assertCount(2, $currentStep->fresh()->assignments);
        $this->assertSame(ApprovalAssignmentStatus::Pending, $currentStep->fresh()->assignments->last()->status);
        $this->assertNotSame($oldAssignment->id, $currentStep->fresh()->assignments->last()->id);
        $this->assertSame(PurchaseRequestStatus::InApproval, $request->fresh()->status);
        $this->assertSame(ApprovalActionType::Resubmitted, $instance->actions->last()->action);
        $this->assertSame('The description has been clarified.', $instance->actions->last()->comment);
        $this->assertFalse($instance->actions->last()->metadata['routing_changed']);
    }

    public function test_amount_and_department_changes_that_resolve_to_the_same_group_resume_the_existing_runtime(): void
    {
        $approver = User::factory()->create();
        [$instance, $request, $requester] = $this->purchaseRuntime([
            [ApproverType::SpecificUser, (string) $approver->id, 'Review'],
        ]);
        $oldDepartmentId = $request->department_id;
        app(ApprovalService::class)->requestChanges($instance->steps->sole()->assignments->sole(), $approver, 'Correct the total and department.');

        $newDepartment = Department::factory()->create();
        $requester->forceFill(['department_id' => $newDepartment->id])->save();
        $request->refresh()->load('items');
        app(PurchaseRequestService::class)->update($request, $this->purchasePayload($request, [
            'items' => [['description' => 'Updated item', 'quantity' => 2, 'unit_price' => '75.00']],
            'tax_amount' => '0.00',
        ]), $requester);

        $this->actingAs($requester)->post(route('purchase-requests.resubmit', $request))->assertRedirect();

        $instance->refresh()->load(['steps.assignments', 'actions']);
        $this->assertDatabaseCount('approval_instances', 1);
        $this->assertSame('150.00', $instance->workflow_context['amount']);
        $this->assertNotSame($oldDepartmentId, $instance->workflow_context['department_id']);
        $this->assertSame($newDepartment->id, $instance->workflow_context['department_id']);
        $this->assertSame(ApprovalAssignmentStatus::Pending, $instance->steps->sole()->assignments->last()->status);
        $this->assertTrue($instance->actions->last()->metadata['routing_changed']);
    }

    public function test_category_change_to_a_different_rule_group_cancels_old_runtime_and_restarts_from_step_one(): void
    {
        $originalApprover = User::factory()->create();
        $newApprover = User::factory()->create();
        $department = Department::factory()->create();
        $requester = User::factory()->create(['department_id' => $department]);
        $originalCategory = SpendCategory::factory()->create();
        $newCategory = SpendCategory::factory()->create();
        $request = $this->purchaseRequest($requester, $department, $originalCategory);
        [$version, $defaultGroup] = $this->draftVersion(
            WorkflowModuleType::PurchaseRequest,
            [[ApproverType::SpecificUser, (string) $originalApprover->id, 'Original review']],
        );
        $changedGroup = $version->ruleGroups()->create([
            'name' => 'Changed category route',
            'priority' => 1,
            'is_default' => false,
        ]);
        $changedGroup->rules()->create([
            'field' => WorkflowRuleField::Category,
            'operator' => WorkflowRuleOperator::Equal,
            'value' => $newCategory->id,
        ]);
        $this->addSteps($changedGroup, [[ApproverType::SpecificUser, (string) $newApprover->id, 'New route review']]);
        $this->publish($version);
        $oldInstance = $this->startPurchaseRuntime($request, $requester);
        app(ApprovalService::class)->requestChanges($oldInstance->steps->sole()->assignments->sole(), $originalApprover, 'Use the correct category.');

        $request->refresh()->load('items');
        app(PurchaseRequestService::class)->update($request, $this->purchasePayload($request, [
            'category_id' => $newCategory->id,
        ]), $requester);
        $this->actingAs($requester)->post(route('purchase-requests.resubmit', $request))->assertRedirect();

        $instances = ApprovalInstance::query()
            ->where('approvable_type', $request->getMorphClass())
            ->where('approvable_id', $request->id)
            ->with('steps.assignments')
            ->orderBy('id')
            ->get();
        $this->assertCount(2, $instances);
        $this->assertSame(ApprovalInstanceStatus::Cancelled, $instances->first()->status);
        $this->assertSame(ApprovalStepStatus::Cancelled, $instances->first()->steps->sole()->status);
        $this->assertSame(ApprovalInstanceStatus::InProgress, $instances->last()->status);
        $this->assertSame($changedGroup->id, $instances->last()->workflow_rule_group_id);
        $this->assertSame(1, $instances->last()->current_step_order);
        $this->assertSame($newApprover->id, $instances->last()->steps->sole()->assignments->sole()->approver_id);
        $action = $instances->last()->actions()->where('action', ApprovalActionType::Resubmitted)->sole();
        $this->assertSame($instances->first()->id, $action->metadata['previous_approval_instance_id']);
        $this->assertSame($instances->last()->id, $action->metadata['new_approval_instance_id']);
        $this->assertNotSame($defaultGroup->id, $instances->last()->workflow_rule_group_id);
    }

    public function test_non_material_resubmission_stays_on_original_version_after_a_new_version_is_published(): void
    {
        $approver = User::factory()->create();
        [$instance, $request, $requester, $template, $oldVersion] = $this->purchaseRuntime([
            [ApproverType::SpecificUser, (string) $approver->id, 'Original review'],
        ]);
        app(ApprovalService::class)->requestChanges($instance->steps->sole()->assignments->sole(), $approver, 'Attach supporting information.');
        $request->attachments()->create($this->attachmentAttributes($requester));
        [$newVersion] = $this->draftVersion(
            WorkflowModuleType::PurchaseRequest,
            [[ApproverType::SpecificUser, (string) $approver->id, 'New policy review']],
            $template,
            2,
        );
        DB::table('workflow_versions')->where('id', $oldVersion->id)->update(['status' => WorkflowVersionStatus::Archived->value]);
        $this->publish($newVersion);

        $this->actingAs($requester)->post(route('purchase-requests.resubmit', $request))->assertRedirect();

        $this->assertDatabaseCount('approval_instances', 1);
        $this->assertSame($oldVersion->id, $instance->fresh()->workflow_version_id);
        $this->assertSame(ApprovalInstanceStatus::InProgress, $instance->fresh()->status);
    }

    public function test_material_change_uses_new_published_version_and_preserves_cancelled_old_history(): void
    {
        $oldApprover = User::factory()->create();
        $newApprover = User::factory()->create();
        [$oldInstance, $request, $requester, $template, $oldVersion] = $this->purchaseRuntime([
            [ApproverType::SpecificUser, (string) $oldApprover->id, 'Old policy review'],
        ]);
        app(ApprovalService::class)->requestChanges($oldInstance->steps->sole()->assignments->sole(), $oldApprover, 'Correct the amount.');
        [$newVersion] = $this->draftVersion(
            WorkflowModuleType::PurchaseRequest,
            [[ApproverType::SpecificUser, (string) $newApprover->id, 'New policy review']],
            $template,
            2,
        );
        DB::table('workflow_versions')->where('id', $oldVersion->id)->update(['status' => WorkflowVersionStatus::Archived->value]);
        $this->publish($newVersion);
        $request->refresh()->load('items');
        app(PurchaseRequestService::class)->update($request, $this->purchasePayload($request, [
            'items' => [['description' => 'Materially revised item', 'quantity' => 1, 'unit_price' => '900.00']],
        ]), $requester);

        $this->actingAs($requester)->post(route('purchase-requests.resubmit', $request))->assertRedirect();

        $instances = ApprovalInstance::query()
            ->where('approvable_type', $request->getMorphClass())
            ->where('approvable_id', $request->id)
            ->orderBy('id')
            ->get();
        $this->assertCount(2, $instances);
        $this->assertSame(ApprovalInstanceStatus::Cancelled, $instances->first()->status);
        $this->assertSame($newVersion->id, $instances->last()->workflow_version_id);
        $this->assertSame(ApprovalInstanceStatus::InProgress, $instances->last()->status);
        $this->assertDatabaseHas('approval_actions', [
            'approval_instance_id' => $oldInstance->id,
            'action' => ApprovalActionType::ChangesRequested->value,
        ]);
    }

    public function test_invalid_replacement_route_rolls_back_without_cancelling_existing_runtime_or_business_state(): void
    {
        $approver = User::factory()->create();
        [$oldInstance, $request, $requester, $template, $oldVersion] = $this->purchaseRuntime([
            [ApproverType::SpecificUser, (string) $approver->id, 'Original review'],
        ]);
        app(ApprovalService::class)->requestChanges($oldInstance->steps->sole()->assignments->sole(), $approver, 'Correct the amount.');
        [$invalidVersion] = $this->draftVersion(
            WorkflowModuleType::PurchaseRequest,
            [[ApproverType::SpecificUser, '999999', 'Unavailable reviewer']],
            $template,
            2,
        );
        DB::table('workflow_versions')->where('id', $oldVersion->id)->update(['status' => WorkflowVersionStatus::Archived->value]);
        $this->publish($invalidVersion);
        $request->refresh()->load('items');
        app(PurchaseRequestService::class)->update($request, $this->purchasePayload($request, [
            'items' => [['description' => 'Revised item', 'quantity' => 1, 'unit_price' => '700.00']],
        ]), $requester);
        $actionCount = $oldInstance->actions()->count();

        $this->actingAs($requester)
            ->from(route('purchase-requests.show', $request))
            ->post(route('purchase-requests.resubmit', $request))
            ->assertRedirect(route('purchase-requests.show', $request))
            ->assertSessionHasErrors('workflow');

        $this->assertDatabaseCount('approval_instances', 1);
        $this->assertSame(ApprovalInstanceStatus::InProgress, $oldInstance->fresh()->status);
        $this->assertSame(ApprovalStepStatus::Active, $oldInstance->steps()->sole()->status);
        $this->assertSame(PurchaseRequestStatus::ChangesRequested, $request->fresh()->status);
        $this->assertSame($actionCount, $oldInstance->actions()->count());
    }

    public function test_supplier_invoice_and_expense_claim_support_non_material_resubmission_with_persisted_attachments(): void
    {
        $invoiceApprover = User::factory()->create();
        $finance = User::factory()->create(['role' => UserRole::Finance]);
        $invoice = SupplierInvoice::factory()->create(['submitted_by' => $finance]);
        SupplierInvoiceItem::factory()->create(['supplier_invoice_id' => $invoice]);
        $invoice->attachments()->create($this->attachmentAttributes($finance));
        [$invoiceVersion] = $this->draftVersion(
            WorkflowModuleType::SupplierInvoice,
            [[ApproverType::SpecificUser, (string) $invoiceApprover->id, 'Invoice review']],
        );
        $this->publish($invoiceVersion);
        $invoiceInstance = $this->startRuntime(
            $invoice,
            WorkflowModuleType::SupplierInvoice,
            $finance,
            $invoice->department_id,
            $invoice->category_id,
            $invoice->total_amount,
        );
        app(ApprovalService::class)->requestChanges($invoiceInstance->steps->sole()->assignments->sole(), $invoiceApprover, 'Clarify the invoice.');

        $this->actingAs($finance)->post(route('supplier-invoices.resubmit', $invoice))->assertRedirect();
        $this->assertSame(SupplierInvoiceStatus::InApproval, $invoice->fresh()->status);
        $this->assertDatabaseCount('approval_instances', 1);
        $this->assertSame(2, $invoiceInstance->steps()->sole()->assignments()->count());

        $expenseApprover = User::factory()->create();
        $department = Department::factory()->create();
        $employee = User::factory()->create(['department_id' => $department]);
        $claim = ExpenseClaim::factory()->create(['employee_id' => $employee, 'department_id' => $department]);
        $item = ExpenseItem::factory()->create(['expense_claim_id' => $claim]);
        $item->attachments()->create($this->attachmentAttributes($employee));
        [$expenseVersion] = $this->draftVersion(
            WorkflowModuleType::ExpenseClaim,
            [[ApproverType::SpecificUser, (string) $expenseApprover->id, 'Expense review']],
        );
        $this->publish($expenseVersion);
        $claimInstance = $this->startRuntime(
            $claim,
            WorkflowModuleType::ExpenseClaim,
            $employee,
            $department->id,
            $item->category_id,
            $claim->total_amount,
        );
        app(ApprovalService::class)->requestChanges($claimInstance->steps->sole()->assignments->sole(), $expenseApprover, 'Add receipt details.');

        $this->actingAs($employee)->post(route('expense-claims.resubmit', $claim))->assertRedirect();
        $this->assertSame(ExpenseClaimStatus::InApproval, $claim->fresh()->status);
        $this->assertSame(2, $claimInstance->steps()->sole()->assignments()->count());
    }

    public function test_withdrawal_from_in_approval_cancels_actionable_runtime_and_wins_over_stale_approval(): void
    {
        $approver = User::factory()->create();
        [$instance, $request, $requester] = $this->purchaseRuntime([
            [ApproverType::SpecificUser, (string) $approver->id, 'Current review'],
            [ApproverType::SpecificUser, (string) User::factory()->create()->id, 'Future review'],
        ]);
        $assignment = $instance->steps->first()->assignments->sole();

        $this->actingAs($requester)->post(route('purchase-requests.withdraw', $request), [
            'comment' => 'No longer required.',
        ])->assertRedirect();

        $instance->refresh()->load(['steps', 'actions']);
        $this->assertSame(PurchaseRequestStatus::Withdrawn, $request->fresh()->status);
        $this->assertNotNull($request->fresh()->withdrawn_at);
        $this->assertSame(ApprovalInstanceStatus::Cancelled, $instance->status);
        $this->assertNull($instance->current_step_order);
        $this->assertSame([ApprovalStepStatus::Cancelled, ApprovalStepStatus::Cancelled], $instance->steps->pluck('status')->all());
        $this->assertSame(ApprovalAssignmentStatus::Cancelled, $assignment->fresh()->status);
        $this->assertSame(ApprovalActionType::Withdrawn, $instance->actions->last()->action);

        $this->actingAs($approver)
            ->from(route('approvals.index'))
            ->post(route('approval-assignments.approve', $assignment))
            ->assertRedirect(route('approvals.index'))
            ->assertSessionHasErrors('action');
        $this->assertSame(PurchaseRequestStatus::Withdrawn, $request->fresh()->status);
        $this->assertSame(ApprovalInstanceStatus::Cancelled, $instance->fresh()->status);
    }

    public function test_withdrawal_from_changes_requested_is_allowed_and_repeated_or_final_state_operations_are_forbidden(): void
    {
        $approver = User::factory()->create();
        [$instance, $request, $requester] = $this->purchaseRuntime([
            [ApproverType::SpecificUser, (string) $approver->id, 'Review'],
        ]);
        app(ApprovalService::class)->requestChanges($instance->steps->sole()->assignments->sole(), $approver, 'Please revise.');

        $this->actingAs($requester)->post(route('purchase-requests.withdraw', $request))->assertRedirect();
        $this->assertSame(PurchaseRequestStatus::Withdrawn, $request->fresh()->status);
        $this->assertSame(ApprovalStepStatus::Cancelled, $instance->steps()->sole()->status);
        $this->actingAs($requester)->post(route('purchase-requests.withdraw', $request))->assertForbidden();
        $this->actingAs($requester)->post(route('purchase-requests.resubmit', $request))->assertForbidden();

        DB::table('purchase_requests')->where('id', $request->id)->update(['status' => PurchaseRequestStatus::Rejected->value]);
        $request->refresh();
        $this->actingAs($requester)->post(route('purchase-requests.resubmit', $request))->assertForbidden();
    }

    public function test_supplier_invoice_and_expense_claim_withdrawal_preserve_runtime_history(): void
    {
        $invoiceApprover = User::factory()->create();
        $finance = User::factory()->create(['role' => UserRole::Finance]);
        $invoice = SupplierInvoice::factory()->create(['submitted_by' => $finance]);
        SupplierInvoiceItem::factory()->create(['supplier_invoice_id' => $invoice]);
        [$invoiceVersion] = $this->draftVersion(
            WorkflowModuleType::SupplierInvoice,
            [[ApproverType::SpecificUser, (string) $invoiceApprover->id, 'Invoice review']],
        );
        $this->publish($invoiceVersion);
        $invoiceInstance = $this->startRuntime(
            $invoice,
            WorkflowModuleType::SupplierInvoice,
            $finance,
            $invoice->department_id,
            $invoice->category_id,
            $invoice->total_amount,
        );

        $this->actingAs($finance)->post(route('supplier-invoices.withdraw', $invoice))->assertRedirect();

        $this->assertSame(SupplierInvoiceStatus::Withdrawn, $invoice->fresh()->status);
        $this->assertSame(ApprovalInstanceStatus::Cancelled, $invoiceInstance->fresh()->status);
        $this->assertSame(ApprovalAssignmentStatus::Cancelled, $invoiceInstance->steps()->sole()->assignments()->sole()->status);
        $this->assertTrue($invoiceInstance->actions()->where('action', ApprovalActionType::Submitted->value)->exists());
        $this->assertTrue($invoiceInstance->actions()->where('action', ApprovalActionType::Withdrawn->value)->exists());

        $expenseApprover = User::factory()->create();
        $department = Department::factory()->create();
        $employee = User::factory()->create(['department_id' => $department]);
        $claim = ExpenseClaim::factory()->create(['employee_id' => $employee, 'department_id' => $department]);
        $item = ExpenseItem::factory()->create(['expense_claim_id' => $claim]);
        [$expenseVersion] = $this->draftVersion(
            WorkflowModuleType::ExpenseClaim,
            [[ApproverType::SpecificUser, (string) $expenseApprover->id, 'Expense review']],
        );
        $this->publish($expenseVersion);
        $claimInstance = $this->startRuntime(
            $claim,
            WorkflowModuleType::ExpenseClaim,
            $employee,
            $department->id,
            $item->category_id,
            $claim->total_amount,
        );
        app(ApprovalService::class)->requestChanges($claimInstance->steps->sole()->assignments->sole(), $expenseApprover, 'Please revise.');

        $this->actingAs($employee)->post(route('expense-claims.withdraw', $claim))->assertRedirect();

        $this->assertSame(ExpenseClaimStatus::Withdrawn, $claim->fresh()->status);
        $this->assertSame(ApprovalInstanceStatus::Cancelled, $claimInstance->fresh()->status);
        $this->assertSame(ApprovalStepStatus::Cancelled, $claimInstance->steps()->sole()->status);
        $this->assertTrue($claimInstance->actions()->where('action', ApprovalActionType::ChangesRequested->value)->exists());
        $this->assertTrue($claimInstance->actions()->where('action', ApprovalActionType::Withdrawn->value)->exists());
    }

    public function test_duplicate_resubmit_and_unauthorized_lifecycle_actions_do_not_create_duplicate_state(): void
    {
        $approver = User::factory()->create();
        [$instance, $request, $requester] = $this->purchaseRuntime([
            [ApproverType::SpecificUser, (string) $approver->id, 'Review'],
        ]);
        app(ApprovalService::class)->requestChanges($instance->steps->sole()->assignments->sole(), $approver, 'Please revise.');
        $otherUser = User::factory()->create();

        $this->actingAs($otherUser)->post(route('purchase-requests.resubmit', $request))->assertForbidden();
        $this->actingAs($otherUser)->post(route('purchase-requests.withdraw', $request))->assertForbidden();
        $this->actingAs($requester)->post(route('purchase-requests.resubmit', $request))->assertRedirect();
        $this->actingAs($requester)->post(route('purchase-requests.resubmit', $request))->assertForbidden();

        $this->assertDatabaseCount('approval_instances', 1);
        $this->assertSame(1, $instance->actions()->where('action', ApprovalActionType::Resubmitted->value)->count());
        $this->assertSame(1, $instance->steps()->sole()->assignments()->where('status', ApprovalAssignmentStatus::Pending->value)->count());
    }

    /**
     * @param  list<array{ApproverType, string, string}>  $steps
     * @return array{ApprovalInstance, PurchaseRequest, User, WorkflowTemplate, WorkflowVersion}
     */
    private function purchaseRuntime(array $steps): array
    {
        $department = Department::factory()->create();
        $requester = User::factory()->create(['department_id' => $department]);
        $category = SpendCategory::factory()->create();
        $request = $this->purchaseRequest($requester, $department, $category);
        [$version, , $template] = $this->draftVersion(WorkflowModuleType::PurchaseRequest, $steps);
        $this->publish($version);
        $instance = $this->startPurchaseRuntime($request, $requester);

        return [$instance, $request, $requester, $template, $version];
    }

    private function purchaseRequest(User $requester, Department $department, SpendCategory $category): PurchaseRequest
    {
        $request = PurchaseRequest::factory()->create([
            'requester_id' => $requester,
            'department_id' => $department,
            'category_id' => $category,
            'total_amount' => '100.00',
            'subtotal' => '100.00',
            'tax_amount' => '0.00',
        ]);
        PurchaseRequestItem::factory()->create(['purchase_request_id' => $request]);

        return $request;
    }

    private function startPurchaseRuntime(PurchaseRequest $request, User $requester): ApprovalInstance
    {
        return $this->startRuntime(
            $request,
            WorkflowModuleType::PurchaseRequest,
            $requester,
            $request->department_id,
            $request->category_id,
            $request->total_amount,
        );
    }

    private function startRuntime(
        PurchaseRequest|SupplierInvoice|ExpenseClaim $business,
        WorkflowModuleType $module,
        User $requester,
        int $departmentId,
        int $categoryId,
        string $amount,
    ): ApprovalInstance {
        DB::table($business->getTable())->where('id', $business->id)->update([
            'status' => 'IN_APPROVAL',
            'submitted_at' => now(),
        ]);
        $business->refresh();
        $context = WorkflowContext::fromValues(
            $module,
            $requester->id,
            $departmentId,
            $categoryId,
            $amount,
            $business->currency,
        );

        return app(WorkflowEngine::class)->start($business, app(WorkflowResolver::class)->resolve($context));
    }

    /**
     * @param  list<array{ApproverType, string, string}>  $steps
     * @return array{WorkflowVersion, WorkflowRuleGroup, WorkflowTemplate}
     */
    private function draftVersion(
        WorkflowModuleType $module,
        array $steps,
        ?WorkflowTemplate $template = null,
        int $number = 1,
    ): array {
        $template ??= WorkflowTemplate::factory()->create(['module_type' => $module]);
        $version = WorkflowVersion::factory()->create([
            'workflow_template_id' => $template,
            'version' => $number,
        ]);
        $group = $version->ruleGroups()->create([
            'name' => "Default {$module->value} route",
            'priority' => 100,
            'is_default' => true,
        ]);
        $this->addSteps($group, $steps);

        return [$version, $group, $template];
    }

    /** @param list<array{ApproverType, string, string}> $steps */
    private function addSteps(WorkflowRuleGroup $group, array $steps): void
    {
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
    }

    private function publish(WorkflowVersion $version): void
    {
        DB::table('workflow_versions')->where('id', $version->id)->update([
            'status' => WorkflowVersionStatus::Published->value,
            'published_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function purchasePayload(PurchaseRequest $request, array $overrides = []): array
    {
        $request->loadMissing('items');

        return array_replace([
            'lock_version' => $request->lock_version,
            'title' => $request->title,
            'description' => $request->description,
            'category_id' => $request->category_id,
            'vendor_id' => $request->vendor_id,
            'needed_by_date' => $request->needed_by_date?->toDateString(),
            'tax_amount' => $request->tax_amount,
            'items' => $request->items->map->only(['description', 'quantity', 'unit_price'])->all(),
        ], $overrides);
    }

    /** @return array<string, mixed> */
    private function attachmentAttributes(User $uploader): array
    {
        return [
            'original_name' => 'evidence.pdf',
            'stored_name' => fake()->uuid().'.pdf',
            'disk' => 'local',
            'path' => 'attachments/'.fake()->uuid().'.pdf',
            'mime_type' => 'application/pdf',
            'size' => 100,
            'uploaded_by' => $uploader->id,
        ];
    }
}
