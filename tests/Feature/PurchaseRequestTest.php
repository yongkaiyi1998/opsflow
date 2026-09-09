<?php

namespace Tests\Feature;

use App\ApprovalActionType;
use App\ApprovalAssignmentStatus;
use App\ApprovalMode;
use App\ApprovalStepStatus;
use App\ApproverType;
use App\MasterDataStatus;
use App\Models\ApprovalInstance;
use App\Models\Department;
use App\Models\PurchaseRequest;
use App\Models\SpendCategory;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkflowRuleGroup;
use App\Models\WorkflowTemplate;
use App\Models\WorkflowVersion;
use App\PurchaseRequestStatus;
use App\Services\PurchaseRequestService;
use App\WorkflowModuleType;
use App\WorkflowVersionStatus;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PurchaseRequestTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_employee_creates_a_draft_with_server_derived_identity_reference_and_totals(): void
    {
        $department = Department::factory()->create();
        $otherDepartment = Department::factory()->create();
        $requester = User::factory()->create(['department_id' => $department]);
        $category = SpendCategory::factory()->create();
        $vendor = Vendor::factory()->create();
        $otherUser = User::factory()->create(['department_id' => $otherDepartment]);

        $response = $this->actingAs($requester)->post(route('purchase-requests.store'), array_merge(
            $this->payload($category, $vendor),
            [
                'requester_id' => $otherUser->id,
                'department_id' => $otherDepartment->id,
                'status' => PurchaseRequestStatus::Approved->value,
                'subtotal' => '1.00',
                'total_amount' => '1.00',
            ],
        ));

        $purchaseRequest = PurchaseRequest::query()->sole();
        $response->assertRedirect(route('purchase-requests.show', $purchaseRequest));
        $this->assertSame($requester->id, $purchaseRequest->requester_id);
        $this->assertSame($department->id, $purchaseRequest->department_id);
        $this->assertSame(PurchaseRequestStatus::Draft, $purchaseRequest->status);
        $this->assertMatchesRegularExpression('/^PR-\d{4}-000001$/', $purchaseRequest->request_no);
        $this->assertSame('20.80', $purchaseRequest->subtotal);
        $this->assertSame('1.25', $purchaseRequest->tax_amount);
        $this->assertSame('22.05', $purchaseRequest->total_amount);
        $this->assertSame(
            [
                ['description' => 'USB-C adapter', 'quantity' => 2, 'unit_price' => '10.25', 'subtotal' => '20.50'],
                ['description' => 'Cable tie', 'quantity' => 3, 'unit_price' => '0.10', 'subtotal' => '0.30'],
            ],
            $purchaseRequest->items->map->only(['description', 'quantity', 'unit_price', 'subtotal'])->all(),
        );
        $this->assertDatabaseCount('approval_instances', 0);
    }

    public function test_requester_can_replace_draft_items_but_cannot_overwrite_a_newer_edit(): void
    {
        [$requester, $category, $vendor] = $this->requestData();
        $purchaseRequest = $this->createDraft($requester, $category, $vendor);
        $firstEdit = $this->payload($category, $vendor, [
            'lock_version' => 1,
            'title' => 'Updated equipment request',
            'tax_amount' => '2.00',
            'items' => [['description' => 'Display', 'quantity' => 2, 'unit_price' => '999.99']],
        ]);

        $this->actingAs($requester)->put(route('purchase-requests.update', $purchaseRequest), $firstEdit)
            ->assertRedirect(route('purchase-requests.show', $purchaseRequest));

        $purchaseRequest->refresh();
        $this->assertSame('Updated equipment request', $purchaseRequest->title);
        $this->assertSame(2, $purchaseRequest->lock_version);
        $this->assertSame('1999.98', $purchaseRequest->subtotal);
        $this->assertSame('2001.98', $purchaseRequest->total_amount);
        $this->assertCount(1, $purchaseRequest->items);

        $staleEdit = array_merge($firstEdit, ['title' => 'Stale overwrite']);
        $this->actingAs($requester)
            ->from(route('purchase-requests.edit', $purchaseRequest))
            ->put(route('purchase-requests.update', $purchaseRequest), $staleEdit)
            ->assertRedirect(route('purchase-requests.edit', $purchaseRequest))
            ->assertSessionHasErrors('lock_version');

        $this->assertSame('Updated equipment request', $purchaseRequest->fresh()->title);
    }

    public function test_purchase_request_validation_requires_active_master_data_and_integer_quantities(): void
    {
        $department = Department::factory()->create();
        $requester = User::factory()->create(['department_id' => $department]);
        $category = SpendCategory::factory()->create(['status' => MasterDataStatus::Inactive]);
        $vendor = Vendor::factory()->create(['status' => MasterDataStatus::Inactive]);
        $payload = $this->payload($category, $vendor, [
            'items' => [['description' => 'Fractional item', 'quantity' => '1.5', 'unit_price' => '10.00']],
        ]);

        $this->actingAs($requester)->post(route('purchase-requests.store'), $payload)
            ->assertSessionHasErrors(['category_id', 'vendor_id', 'items.0.quantity']);

        $this->assertDatabaseCount('purchase_requests', 0);
    }

    public function test_ownership_and_assignment_control_list_detail_edit_and_submit_access(): void
    {
        [$owner, $category, $vendor] = $this->requestData();
        [$otherUser, $otherCategory, $otherVendor] = $this->requestData();
        $admin = User::factory()->admin()->create();
        $this->createDraft($owner, $category, $vendor, ['title' => 'Owned request']);
        $other = $this->createDraft($otherUser, $otherCategory, $otherVendor, ['title' => 'Private request']);

        $this->actingAs($owner)->get(route('purchase-requests.index'))
            ->assertOk()
            ->assertSee('Owned request')
            ->assertDontSee('Private request');
        $this->actingAs($owner)->get(route('purchase-requests.show', $other))->assertForbidden();
        $this->actingAs($owner)->put(route('purchase-requests.update', $other), $this->payload($otherCategory, $otherVendor, ['lock_version' => 1]))->assertForbidden();
        $this->actingAs($owner)->post(route('purchase-requests.submit', $other))->assertForbidden();

        $this->actingAs($admin)->get(route('purchase-requests.index'))
            ->assertOk()
            ->assertSee('Owned request')
            ->assertSee('Private request');
    }

    public function test_private_attachment_and_all_draft_children_are_removed_with_the_draft_without_reusing_its_reference(): void
    {
        [$requester, $category, $vendor] = $this->requestData();
        $purchaseRequest = $this->createDraft($requester, $category, $vendor);

        $this->actingAs($requester)->post(
            route('purchase-request-attachments.store', $purchaseRequest),
            ['attachment' => UploadedFile::fake()->create('quote.pdf', 50, 'application/pdf')],
        )->assertRedirect();

        $attachment = $purchaseRequest->attachments()->sole();
        Storage::disk('local')->assertExists($attachment->path);
        Storage::disk('public')->assertMissing($attachment->path);

        $this->actingAs($requester)->delete(route('purchase-requests.destroy', $purchaseRequest))
            ->assertRedirect(route('purchase-requests.index'));

        $this->assertDatabaseMissing('purchase_requests', ['id' => $purchaseRequest->id]);
        $this->assertDatabaseMissing('purchase_request_items', ['purchase_request_id' => $purchaseRequest->id]);
        $this->assertDatabaseMissing('attachments', ['id' => $attachment->id]);
        Storage::disk('local')->assertMissing($attachment->path);

        $nextRequest = $this->createDraft($requester, $category, $vendor);
        $this->assertStringEndsWith('000002', $nextRequest->request_no);
    }

    public function test_valid_submission_uses_current_server_context_and_creates_one_complete_runtime(): void
    {
        $manager = User::factory()->create();
        $futureApprover = User::factory()->create();
        $originalDepartment = Department::factory()->create();
        $submissionDepartment = Department::factory()->create();
        $requester = User::factory()->create([
            'department_id' => $originalDepartment,
            'manager_id' => $manager,
        ]);
        $category = SpendCategory::factory()->create();
        $vendor = Vendor::factory()->create();
        [$version, $group] = $this->publishedRoute([
            [ApproverType::RequesterManager, null, 'Manager review'],
            [ApproverType::SpecificUser, (string) $futureApprover->id, 'Procurement review'],
        ]);
        $purchaseRequest = $this->createDraft($requester, $category, $vendor);
        $requester->forceFill(['department_id' => $submissionDepartment->id])->save();
        DB::table('purchase_request_items')->where('purchase_request_id', $purchaseRequest->id)->update(['subtotal' => '999.00']);
        DB::table('purchase_requests')->where('id', $purchaseRequest->id)->update([
            'subtotal' => '999.00',
            'total_amount' => '999.00',
        ]);

        $this->actingAs($requester)->post(route('purchase-requests.submit', $purchaseRequest))
            ->assertRedirect(route('purchase-requests.show', $purchaseRequest));

        $purchaseRequest->refresh();
        $runtime = ApprovalInstance::query()->with(['steps.assignments', 'actions'])->sole();
        $this->assertSame(PurchaseRequestStatus::InApproval, $purchaseRequest->status);
        $this->assertNotNull($purchaseRequest->submitted_at);
        $this->assertSame($submissionDepartment->id, $purchaseRequest->department_id);
        $this->assertSame('20.80', $purchaseRequest->subtotal);
        $this->assertSame('22.05', $purchaseRequest->total_amount);
        $this->assertSame($version->id, $runtime->workflow_version_id);
        $this->assertSame($group->id, $runtime->workflow_rule_group_id);
        $this->assertSame([
            'module_type' => WorkflowModuleType::PurchaseRequest->value,
            'requester_id' => $requester->id,
            'department_id' => $submissionDepartment->id,
            'category_id' => $category->id,
            'amount' => '22.05',
            'currency' => 'MYR',
        ], $runtime->workflow_context);
        $this->assertSame([ApprovalStepStatus::Active, ApprovalStepStatus::Waiting], $runtime->steps->pluck('status')->all());
        $this->assertSame($manager->id, $runtime->steps->first()->assignments->sole()->approver_id);
        $this->assertSame(ApprovalAssignmentStatus::Pending, $runtime->steps->first()->assignments->sole()->status);
        $this->assertCount(0, $runtime->steps->last()->assignments);
        $this->assertSame(ApprovalActionType::Submitted, $runtime->actions->sole()->action);
        $this->assertSame($requester->id, $runtime->actions->sole()->actor_id);

        $this->actingAs($manager)->get(route('purchase-requests.show', $purchaseRequest))
            ->assertOk()
            ->assertSee('Approval timeline')
            ->assertSee('Manager review');
        $this->actingAs(User::factory()->create())->get(route('purchase-requests.show', $purchaseRequest))->assertForbidden();
    }

    public function test_missing_workflow_rolls_back_recalculated_values_and_runtime_creation(): void
    {
        [$requester, $category, $vendor] = $this->requestData();
        $purchaseRequest = $this->createDraft($requester, $category, $vendor);
        DB::table('purchase_request_items')->where('purchase_request_id', $purchaseRequest->id)->update(['subtotal' => '1.00']);
        DB::table('purchase_requests')->where('id', $purchaseRequest->id)->update([
            'subtotal' => '1.00',
            'total_amount' => '1.00',
        ]);

        $this->actingAs($requester)
            ->from(route('purchase-requests.show', $purchaseRequest))
            ->post(route('purchase-requests.submit', $purchaseRequest))
            ->assertRedirect(route('purchase-requests.show', $purchaseRequest))
            ->assertSessionHasErrors('workflow');

        $purchaseRequest->refresh();
        $this->assertSame(PurchaseRequestStatus::Draft, $purchaseRequest->status);
        $this->assertSame('1.00', $purchaseRequest->subtotal);
        $this->assertSame('1.00', $purchaseRequest->items()->firstOrFail()->subtotal);
        $this->assertDatabaseCount('approval_instances', 0);
        $this->assertDatabaseCount('approval_actions', 0);
    }

    public function test_missing_first_approver_rolls_back_submission_and_all_runtime_rows(): void
    {
        $department = Department::factory()->create();
        $requester = User::factory()->create(['department_id' => $department, 'manager_id' => null]);
        $category = SpendCategory::factory()->create();
        $vendor = Vendor::factory()->create();
        $this->publishedRoute([[ApproverType::RequesterManager, null, 'Manager review']]);
        $purchaseRequest = $this->createDraft($requester, $category, $vendor);

        $this->actingAs($requester)
            ->from(route('purchase-requests.show', $purchaseRequest))
            ->post(route('purchase-requests.submit', $purchaseRequest))
            ->assertRedirect(route('purchase-requests.show', $purchaseRequest))
            ->assertSessionHasErrors('workflow');

        $this->assertSame(PurchaseRequestStatus::Draft, $purchaseRequest->fresh()->status);
        $this->assertDatabaseCount('approval_instances', 0);
        $this->assertDatabaseCount('approval_step_instances', 0);
        $this->assertDatabaseCount('approval_assignments', 0);
        $this->assertDatabaseCount('approval_actions', 0);
    }

    public function test_a_second_submission_is_rejected_without_a_second_runtime(): void
    {
        $manager = User::factory()->create();
        $department = Department::factory()->create();
        $requester = User::factory()->create(['department_id' => $department, 'manager_id' => $manager]);
        $category = SpendCategory::factory()->create();
        $vendor = Vendor::factory()->create();
        $this->publishedRoute([[ApproverType::RequesterManager, null, 'Manager review']]);
        $purchaseRequest = $this->createDraft($requester, $category, $vendor);

        $this->actingAs($requester)->post(route('purchase-requests.submit', $purchaseRequest))->assertRedirect();
        $this->actingAs($requester)->post(route('purchase-requests.submit', $purchaseRequest))->assertForbidden();

        $this->assertDatabaseCount('approval_instances', 1);
        $this->assertDatabaseCount('approval_step_instances', 1);
        $this->assertDatabaseCount('approval_assignments', 1);
        $this->assertDatabaseCount('approval_actions', 1);
    }

    public function test_submitted_requests_cannot_be_edited_deleted_or_receive_new_attachments(): void
    {
        $manager = User::factory()->create();
        $department = Department::factory()->create();
        $requester = User::factory()->create(['department_id' => $department, 'manager_id' => $manager]);
        $category = SpendCategory::factory()->create();
        $vendor = Vendor::factory()->create();
        $this->publishedRoute([[ApproverType::RequesterManager, null, 'Manager review']]);
        $purchaseRequest = $this->createDraft($requester, $category, $vendor);
        $this->actingAs($requester)->post(route('purchase-requests.submit', $purchaseRequest));

        $this->actingAs($requester)->put(
            route('purchase-requests.update', $purchaseRequest),
            $this->payload($category, $vendor, ['lock_version' => 2]),
        )->assertForbidden();
        $this->actingAs($requester)->delete(route('purchase-requests.destroy', $purchaseRequest))->assertForbidden();
        $this->actingAs($requester)->post(
            route('purchase-request-attachments.store', $purchaseRequest),
            ['attachment' => UploadedFile::fake()->create('late.pdf', 10, 'application/pdf')],
        )->assertForbidden();

        $this->assertModelExists($purchaseRequest);
        $this->assertDatabaseCount('approval_instances', 1);
        $this->assertDatabaseCount('attachments', 0);
    }

    /** @return array{User, SpendCategory, Vendor} */
    private function requestData(): array
    {
        $department = Department::factory()->create();

        return [
            User::factory()->create(['department_id' => $department]),
            SpendCategory::factory()->create(),
            Vendor::factory()->create(),
        ];
    }

    /** @param array<string, mixed> $overrides */
    private function createDraft(User $requester, SpendCategory $category, Vendor $vendor, array $overrides = []): PurchaseRequest
    {
        return app(PurchaseRequestService::class)->create(
            $this->payload($category, $vendor, $overrides),
            $requester,
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(SpendCategory $category, Vendor $vendor, array $overrides = []): array
    {
        return array_replace([
            'title' => 'Developer workstation accessories',
            'description' => 'Accessories are required for the new developer workstation.',
            'category_id' => $category->id,
            'vendor_id' => $vendor->id,
            'needed_by_date' => today()->addWeek()->toDateString(),
            'tax_amount' => '1.25',
            'items' => [
                ['description' => 'USB-C adapter', 'quantity' => 2, 'unit_price' => '10.25'],
                ['description' => 'Cable tie', 'quantity' => 3, 'unit_price' => '0.10'],
            ],
        ], $overrides);
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
            'name' => 'Default purchase request route',
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
