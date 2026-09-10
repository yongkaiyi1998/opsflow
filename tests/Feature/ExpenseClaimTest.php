<?php

namespace Tests\Feature;

use App\ApprovalActionType;
use App\ApprovalAssignmentStatus;
use App\ApprovalMode;
use App\ApprovalStepStatus;
use App\ApproverType;
use App\ExpenseClaimStatus;
use App\MasterDataStatus;
use App\Models\ApprovalInstance;
use App\Models\Attachment;
use App\Models\Department;
use App\Models\ExpenseClaim;
use App\Models\ExpenseItem;
use App\Models\SpendCategory;
use App\Models\User;
use App\Models\WorkflowRuleGroup;
use App\Models\WorkflowTemplate;
use App\Models\WorkflowVersion;
use App\Services\AttachmentService;
use App\Services\ExpenseClaimService;
use App\UserRole;
use App\WorkflowModuleType;
use App\WorkflowVersionStatus;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ExpenseClaimTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_employee_creates_a_draft_with_server_derived_identity_reference_and_gross_total(): void
    {
        [$employee, $category] = $this->claimData();
        $otherDepartment = Department::factory()->create();
        $otherEmployee = User::factory()->create(['department_id' => $otherDepartment]);
        $payload = array_merge($this->payload($category), [
            'employee_id' => $otherEmployee->id,
            'department_id' => $otherDepartment->id,
            'claim_no' => 'FORGED-001',
            'status' => ExpenseClaimStatus::Approved->value,
            'total_amount' => '1.00',
        ]);

        $response = $this->actingAs($employee)->post(route('expense-claims.store'), $payload);

        $claim = ExpenseClaim::query()->sole();
        $response->assertRedirect(route('expense-claims.show', $claim));
        $this->assertMatchesRegularExpression('/^EXP-\d{4}-000001$/', $claim->claim_no);
        $this->assertSame($employee->id, $claim->employee_id);
        $this->assertSame($employee->department_id, $claim->department_id);
        $this->assertSame(ExpenseClaimStatus::Draft, $claim->status);
        $this->assertSame('30.15', $claim->total_amount);
        $this->assertSame(['1.25', '0.50'], $claim->items->pluck('tax_amount')->all());
        $this->assertSame(['20.05', '10.10'], $claim->items->pluck('amount')->all());
        $this->assertTrue($claim->items->every->receipt_required);
        $this->assertDatabaseCount('approval_instances', 0);
    }

    public function test_validation_rejects_future_dates_inactive_categories_and_users_without_departments(): void
    {
        [$employee, $category] = $this->claimData();
        $category->update(['status' => MasterDataStatus::Inactive]);
        $payload = $this->payload($category, [
            'items' => [[
                'category_id' => $category->id,
                'expense_date' => today()->addDay()->toDateString(),
                'merchant' => null,
                'description' => 'Future expense',
                'amount' => '10.00',
                'tax_amount' => '0.00',
            ]],
        ]);

        $this->actingAs($employee)->post(route('expense-claims.store'), $payload)
            ->assertSessionHasErrors(['items.0.category_id', 'items.0.expense_date']);

        $employeeWithoutDepartment = User::factory()->create(['department_id' => null]);
        $this->actingAs($employeeWithoutDepartment)->get(route('expense-claims.create'))->assertForbidden();
        $this->assertDatabaseCount('expense_claims', 0);
    }

    public function test_ownership_and_finance_visibility_control_access_without_granting_edit_rights(): void
    {
        [$owner, $category] = $this->claimData();
        [$otherEmployee, $otherCategory] = $this->claimData();
        $finance = User::factory()->create(['role' => UserRole::Finance]);
        $owned = $this->createDraft($owner, $category, ['title' => 'Owned claim']);
        $private = $this->createDraft($otherEmployee, $otherCategory, ['title' => 'Private claim']);

        $this->actingAs($owner)->get(route('expense-claims.index'))
            ->assertOk()
            ->assertSee('Owned claim')
            ->assertDontSee('Private claim');
        $this->actingAs($owner)->get(route('expense-claims.show', $private))->assertForbidden();
        $this->actingAs($owner)->put(route('expense-claims.update', $private), $this->payload($otherCategory, ['lock_version' => 1]))->assertForbidden();
        $this->actingAs($finance)->get(route('expense-claims.index'))
            ->assertOk()
            ->assertSee('Owned claim')
            ->assertSee('Private claim');
        $this->actingAs($finance)->get(route('expense-claims.show', $owned))->assertOk();
        $this->actingAs($finance)->get(route('expense-claims.edit', $owned))->assertForbidden();
    }

    public function test_edit_preserves_receipts_on_stable_items_and_rejects_stale_updates(): void
    {
        [$employee, $category] = $this->claimData();
        $claim = $this->createDraft($employee, $category);
        $firstItem = $claim->items()->firstOrFail();
        $receipt = $this->attachReceipt($firstItem, $employee);
        $removedItem = $claim->items()->whereKeyNot($firstItem->id)->firstOrFail();
        $removedReceipt = $this->attachReceipt($removedItem, $employee);
        $items = $claim->items->map(fn ($item): array => [
            'id' => $item->id,
            'category_id' => $item->category_id,
            'expense_date' => $item->expense_date->toDateString(),
            'merchant' => $item->merchant,
            'description' => $item->description,
            'amount' => $item->amount,
            'tax_amount' => $item->tax_amount,
        ])->all();
        $items = [$items[0]];
        $items[0]['amount'] = '25.00';
        $edit = $this->payload($category, ['lock_version' => 1, 'title' => 'Updated travel claim', 'items' => $items]);

        $this->actingAs($employee)->put(route('expense-claims.update', $claim), $edit)
            ->assertRedirect(route('expense-claims.show', $claim));

        $claim->refresh();
        $this->assertSame(2, $claim->lock_version);
        $this->assertSame('25.00', $claim->total_amount);
        $this->assertSame($firstItem->id, $claim->items()->firstOrFail()->id);
        $this->assertDatabaseHas('attachments', ['id' => $receipt->id, 'attachable_id' => $firstItem->id]);
        Storage::disk('local')->assertExists($receipt->path);
        $this->assertDatabaseMissing('expense_items', ['id' => $removedItem->id]);
        $this->assertDatabaseMissing('attachments', ['id' => $removedReceipt->id]);
        Storage::disk('local')->assertMissing($removedReceipt->path);

        $this->actingAs($employee)
            ->from(route('expense-claims.edit', $claim))
            ->put(route('expense-claims.update', $claim), array_merge($edit, ['title' => 'Stale overwrite']))
            ->assertRedirect(route('expense-claims.edit', $claim))
            ->assertSessionHasErrors('lock_version');
        $this->assertSame('Updated travel claim', $claim->fresh()->title);
    }

    public function test_submission_requires_a_persisted_private_receipt_for_every_item(): void
    {
        [$employee, $category] = $this->claimData();
        $claim = $this->createDraft($employee, $category);
        $firstItem = $claim->items()->firstOrFail();
        $this->actingAs($employee)->post(
            route('expense-item-attachments.store', $firstItem),
            ['attachment' => UploadedFile::fake()->create('meal.pdf', 20, 'application/pdf')],
        )->assertRedirect();

        $attachment = $firstItem->attachments()->sole();
        Storage::disk('local')->assertExists($attachment->path);
        Storage::disk('public')->assertMissing($attachment->path);
        $this->actingAs($employee)
            ->from(route('expense-claims.show', $claim))
            ->post(route('expense-claims.submit', $claim))
            ->assertRedirect(route('expense-claims.show', $claim))
            ->assertSessionHasErrors('receipt');
        $this->assertSame(ExpenseClaimStatus::Draft, $claim->fresh()->status);
        $this->assertDatabaseCount('approval_instances', 0);
    }

    public function test_valid_submission_recalculates_total_uses_highest_value_category_and_creates_runtime(): void
    {
        [$employee, $firstCategory] = $this->claimData();
        $highestCategory = SpendCategory::factory()->create();
        $approver = User::factory()->create();
        [$version, $group] = $this->publishedRoute($approver);
        $claim = $this->createDraft($employee, $firstCategory, [
            'items' => [
                $this->item($firstCategory, '50.00', '5.00', 'Taxi'),
                $this->item($highestCategory, '100.00', '8.00', 'Hotel'),
            ],
        ]);
        $claim->items->each(fn ($item) => $this->attachReceipt($item, $employee));
        DB::table('expense_claims')->where('id', $claim->id)->update(['total_amount' => '1.00']);

        $this->actingAs($employee)->post(route('expense-claims.submit', $claim))
            ->assertRedirect(route('expense-claims.show', $claim));

        $claim->refresh();
        $runtime = ApprovalInstance::query()->with(['steps.assignments', 'actions'])->sole();
        $this->assertSame(ExpenseClaimStatus::InApproval, $claim->status);
        $this->assertSame('150.00', $claim->total_amount);
        $this->assertSame($version->id, $runtime->workflow_version_id);
        $this->assertSame($group->id, $runtime->workflow_rule_group_id);
        $this->assertSame([
            'module_type' => WorkflowModuleType::ExpenseClaim->value,
            'requester_id' => $employee->id,
            'department_id' => $employee->department_id,
            'category_id' => $highestCategory->id,
            'amount' => '150.00',
            'currency' => 'MYR',
        ], $runtime->workflow_context);
        $this->assertSame(ApprovalStepStatus::Active, $runtime->steps->sole()->status);
        $this->assertSame($approver->id, $runtime->steps->sole()->assignments->sole()->approver_id);
        $this->assertSame(ApprovalAssignmentStatus::Pending, $runtime->steps->sole()->assignments->sole()->status);
        $this->assertSame(ApprovalActionType::Submitted, $runtime->actions->sole()->action);
    }

    public function test_equal_highest_amounts_use_the_earliest_persisted_item_after_reordering(): void
    {
        [$employee, $earliestCategory] = $this->claimData();
        $laterCategory = SpendCategory::factory()->create();
        $approver = User::factory()->create();
        $this->publishedRoute($approver);
        $claim = $this->createDraft($employee, $earliestCategory, [
            'items' => [
                $this->item($earliestCategory, '75.00', '3.00', 'First'),
                $this->item($laterCategory, '75.00', '4.00', 'Second'),
            ],
        ]);
        $persisted = $claim->items()->get();
        $reversed = $persisted->reverse()->values()->map(fn ($item): array => [
            'id' => $item->id,
            'category_id' => $item->category_id,
            'expense_date' => $item->expense_date->toDateString(),
            'merchant' => $item->merchant,
            'description' => $item->description,
            'amount' => $item->amount,
            'tax_amount' => $item->tax_amount,
        ])->all();
        app(ExpenseClaimService::class)->update($claim, $this->payload($earliestCategory, ['lock_version' => 1, 'items' => $reversed]), $employee);
        $claim->refresh()->items->each(fn ($item) => $this->attachReceipt($item, $employee));

        $this->actingAs($employee)->post(route('expense-claims.submit', $claim))->assertRedirect();

        $this->assertSame($earliestCategory->id, ApprovalInstance::query()->sole()->workflow_context['category_id']);
    }

    public function test_submission_revalidates_category_and_requester_department_then_rolls_back_on_failure(): void
    {
        [$employee, $category] = $this->claimData();
        $claim = $this->createDraft($employee, $category);
        $claim->items->each(fn ($item) => $this->attachReceipt($item, $employee));
        $category->update(['status' => MasterDataStatus::Inactive]);
        DB::table('expense_claims')->where('id', $claim->id)->update(['total_amount' => '1.00']);

        $this->actingAs($employee)
            ->from(route('expense-claims.show', $claim))
            ->post(route('expense-claims.submit', $claim))
            ->assertRedirect(route('expense-claims.show', $claim))
            ->assertSessionHasErrors('items');

        $this->assertSame(ExpenseClaimStatus::Draft, $claim->fresh()->status);
        $this->assertSame('1.00', $claim->fresh()->total_amount);
        $this->assertDatabaseCount('approval_instances', 0);
    }

    public function test_missing_workflow_rolls_back_total_status_and_runtime_creation(): void
    {
        [$employee, $category] = $this->claimData();
        $claim = $this->createDraft($employee, $category);
        $claim->items->each(fn ($item) => $this->attachReceipt($item, $employee));
        DB::table('expense_claims')->where('id', $claim->id)->update(['total_amount' => '1.00']);

        $this->actingAs($employee)
            ->from(route('expense-claims.show', $claim))
            ->post(route('expense-claims.submit', $claim))
            ->assertRedirect(route('expense-claims.show', $claim))
            ->assertSessionHasErrors('workflow');

        $this->assertSame(ExpenseClaimStatus::Draft, $claim->fresh()->status);
        $this->assertSame('1.00', $claim->fresh()->total_amount);
        $this->assertDatabaseCount('approval_instances', 0);
        $this->assertDatabaseCount('approval_step_instances', 0);
        $this->assertDatabaseCount('approval_assignments', 0);
        $this->assertDatabaseCount('approval_actions', 0);
    }

    public function test_repeated_submission_and_all_submitted_mutations_are_rejected(): void
    {
        [$employee, $category] = $this->claimData();
        $approver = User::factory()->create();
        $this->publishedRoute($approver);
        $claim = $this->createDraft($employee, $category);
        $claim->items->each(fn ($item) => $this->attachReceipt($item, $employee));
        $item = $claim->items->first();

        $this->actingAs($employee)->post(route('expense-claims.submit', $claim))->assertRedirect();
        $this->actingAs($employee)->post(route('expense-claims.submit', $claim))->assertForbidden();
        $this->actingAs($employee)->put(route('expense-claims.update', $claim), $this->payload($category, ['lock_version' => 2]))->assertForbidden();
        $this->actingAs($employee)->delete(route('expense-claims.destroy', $claim))->assertForbidden();
        $this->actingAs($employee)->post(
            route('expense-item-attachments.store', $item),
            ['attachment' => UploadedFile::fake()->create('late.pdf', 10, 'application/pdf')],
        )->assertForbidden();
        $this->actingAs($employee)->delete(route('attachments.destroy', $item->attachments()->firstOrFail()))->assertForbidden();

        $this->assertDatabaseCount('approval_instances', 1);
        $this->assertDatabaseCount('approval_actions', 1);
        $this->assertModelExists($claim);
    }

    public function test_draft_deletion_removes_items_and_private_receipts_without_reusing_reference(): void
    {
        [$employee, $category] = $this->claimData();
        $claim = $this->createDraft($employee, $category);
        $receipt = $this->attachReceipt($claim->items->first(), $employee);

        $this->actingAs($employee)->delete(route('expense-claims.destroy', $claim))
            ->assertRedirect(route('expense-claims.index'));

        $this->assertDatabaseMissing('expense_claims', ['id' => $claim->id]);
        $this->assertDatabaseMissing('expense_items', ['expense_claim_id' => $claim->id]);
        $this->assertDatabaseMissing('attachments', ['id' => $receipt->id]);
        Storage::disk('local')->assertMissing($receipt->path);
        $nextClaim = $this->createDraft($employee, $category);
        $this->assertStringEndsWith('000002', $nextClaim->claim_no);
    }

    /** @return array{User, SpendCategory} */
    private function claimData(): array
    {
        $department = Department::factory()->create();

        return [
            User::factory()->create(['department_id' => $department]),
            SpendCategory::factory()->create(),
        ];
    }

    /** @param array<string, mixed> $overrides */
    private function createDraft(User $employee, SpendCategory $category, array $overrides = []): ExpenseClaim
    {
        return app(ExpenseClaimService::class)->create($this->payload($category, $overrides), $employee);
    }

    /** @param array<string, mixed> $overrides */
    private function payload(SpendCategory $category, array $overrides = []): array
    {
        return array_replace([
            'title' => 'September travel claim',
            'description' => 'Travel expenses for the customer visit.',
            'items' => [
                $this->item($category, '20.05', '1.25', 'Airport transfer'),
                $this->item($category, '10.10', '0.50', 'Client refreshments'),
            ],
        ], $overrides);
    }

    /** @return array<string, mixed> */
    private function item(SpendCategory $category, string $amount, string $tax, string $description): array
    {
        return [
            'category_id' => $category->id,
            'expense_date' => today()->subDay()->toDateString(),
            'merchant' => 'Example Merchant',
            'description' => $description,
            'amount' => $amount,
            'tax_amount' => $tax,
        ];
    }

    private function attachReceipt(ExpenseItem $item, User $employee): Attachment
    {
        return app(AttachmentService::class)->store(
            $item,
            UploadedFile::fake()->create("receipt-{$item->id}.pdf", 20, 'application/pdf'),
            $employee,
        );
    }

    /** @return array{WorkflowVersion, WorkflowRuleGroup} */
    private function publishedRoute(User $approver): array
    {
        $template = WorkflowTemplate::factory()->create(['module_type' => WorkflowModuleType::ExpenseClaim]);
        $version = WorkflowVersion::factory()->create(['workflow_template_id' => $template]);
        $group = $version->ruleGroups()->create([
            'name' => 'Default expense claim route',
            'priority' => 10,
            'is_default' => true,
        ]);
        $group->steps()->create([
            'step_order' => 1,
            'name' => 'Expense review',
            'approver_type' => ApproverType::SpecificUser,
            'approver_value' => (string) $approver->id,
            'approval_mode' => ApprovalMode::Any,
            'minimum_approvals' => null,
            'sla_hours' => null,
        ]);
        DB::table('workflow_versions')->where('id', $version->id)->update([
            'status' => WorkflowVersionStatus::Published->value,
        ]);

        return [$version, $group];
    }
}
