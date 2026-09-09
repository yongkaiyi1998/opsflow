<?php

namespace Tests\Feature;

use App\ApprovalActionType;
use App\ApprovalAssignmentStatus;
use App\ApprovalMode;
use App\ApprovalStepStatus;
use App\ApproverType;
use App\MasterDataStatus;
use App\Models\ApprovalInstance;
use App\Models\Attachment;
use App\Models\Department;
use App\Models\SpendCategory;
use App\Models\SupplierInvoice;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkflowRuleGroup;
use App\Models\WorkflowTemplate;
use App\Models\WorkflowVersion;
use App\Services\AttachmentService;
use App\Services\SupplierInvoiceService;
use App\SupplierInvoiceStatus;
use App\UserRole;
use App\WorkflowModuleType;
use App\WorkflowVersionStatus;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SupplierInvoiceTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_finance_creates_a_normalized_draft_with_decimal_quantities_and_authoritative_totals(): void
    {
        [$finance, $vendor, $department, $category] = $this->invoiceData();
        $otherUser = User::factory()->admin()->create();
        $payload = array_merge($this->payload($vendor, $department, $category), [
            'invoice_no' => "\u{00A0}SUP-2026 / 001\u{00A0}",
            'submitted_by' => $otherUser->id,
            'internal_no' => 'FORGED-001',
            'status' => SupplierInvoiceStatus::Approved->value,
            'subtotal' => '1.00',
            'total_amount' => '1.00',
        ]);

        $response = $this->actingAs($finance)->post(route('supplier-invoices.store'), $payload);

        $invoice = SupplierInvoice::query()->sole();
        $response->assertRedirect(route('supplier-invoices.show', $invoice));
        $this->assertSame('SUP-2026 / 001', $invoice->invoice_no);
        $this->assertMatchesRegularExpression('/^INV-\d{4}-000001$/', $invoice->internal_no);
        $this->assertSame($finance->id, $invoice->submitted_by);
        $this->assertSame(SupplierInvoiceStatus::Draft, $invoice->status);
        $this->assertSame('12.37', $invoice->subtotal);
        $this->assertSame('0.13', $invoice->tax_amount);
        $this->assertSame('12.50', $invoice->total_amount);
        $this->assertSame([
            ['description' => 'Consulting hours', 'quantity' => '1.2345', 'unit_price' => '10.01', 'subtotal' => '12.36'],
            ['description' => 'Usage adjustment', 'quantity' => '0.0001', 'unit_price' => '100.00', 'subtotal' => '0.01'],
        ], $invoice->items->map->only(['description', 'quantity', 'unit_price', 'subtotal'])->all());
        $this->assertDatabaseCount('approval_instances', 0);
    }

    public function test_only_active_finance_and_admin_users_can_access_invoice_management(): void
    {
        [$finance, $vendor, $department, $category] = $this->invoiceData();
        $admin = User::factory()->admin()->create();
        $employee = User::factory()->create();

        $this->actingAs($finance)->get(route('supplier-invoices.create'))->assertOk();
        $this->actingAs($admin)->get(route('supplier-invoices.create'))->assertOk();
        $this->actingAs($employee)->get(route('supplier-invoices.index'))->assertForbidden();
        $this->actingAs($employee)->get(route('supplier-invoices.create'))->assertForbidden();
        $this->actingAs($employee)->post(
            route('supplier-invoices.store'),
            $this->payload($vendor, $department, $category),
        )->assertForbidden();

        $invoice = $this->createDraft($finance, $vendor, $department, $category);
        $this->actingAs($employee)->get(route('supplier-invoices.show', $invoice))->assertForbidden();
        $this->actingAs($employee)->get(route('supplier-invoices.edit', $invoice))->assertForbidden();
    }

    public function test_validation_requires_active_master_data_valid_dates_and_four_decimal_quantity_precision(): void
    {
        $finance = User::factory()->create(['role' => UserRole::Finance]);
        $vendor = Vendor::factory()->create(['status' => MasterDataStatus::Inactive]);
        $department = Department::factory()->create(['status' => MasterDataStatus::Inactive]);
        $category = SpendCategory::factory()->create(['status' => MasterDataStatus::Inactive]);
        $payload = $this->payload($vendor, $department, $category, [
            'invoice_date' => '2026-09-10',
            'due_date' => '2026-09-09',
            'items' => [['description' => 'Too precise', 'quantity' => '1.00001', 'unit_price' => '1.00']],
        ]);

        $this->actingAs($finance)->post(route('supplier-invoices.store'), $payload)
            ->assertSessionHasErrors(['vendor_id', 'department_id', 'category_id', 'due_date', 'items.0.quantity']);

        $this->assertDatabaseCount('supplier_invoices', 0);
    }

    public function test_vendor_invoice_number_is_unique_after_normalization_but_may_repeat_for_another_vendor(): void
    {
        [$finance, $vendor, $department, $category] = $this->invoiceData();
        $otherVendor = Vendor::factory()->create();
        $first = $this->createDraft($finance, $vendor, $department, $category, ['invoice_no' => '  INV-001  ']);

        $this->actingAs($finance)->post(
            route('supplier-invoices.store'),
            $this->payload($vendor, $department, $category, ['invoice_no' => 'INV-001']),
        )->assertSessionHasErrors('invoice_no');

        $second = $this->createDraft($finance, $otherVendor, $department, $category, ['invoice_no' => 'INV-001']);
        $this->assertSame('INV-001', $first->invoice_no);
        $this->assertSame('INV-001', $second->invoice_no);
        $this->assertDatabaseCount('supplier_invoices', 2);

        $this->actingAs($finance)->put(
            route('supplier-invoices.update', $second),
            $this->payload($vendor, $department, $category, ['invoice_no' => ' INV-001 ', 'lock_version' => 1]),
        )->assertSessionHasErrors('invoice_no');
        $this->assertSame($otherVendor->id, $second->fresh()->vendor_id);
    }

    public function test_database_constraint_is_the_final_vendor_invoice_uniqueness_guard(): void
    {
        $vendor = Vendor::factory()->create();
        SupplierInvoice::factory()->create([
            'internal_no' => 'INV-2026-900001',
            'vendor_id' => $vendor,
            'invoice_no' => 'RACE-001',
        ]);

        try {
            SupplierInvoice::factory()->create([
                'internal_no' => 'INV-2026-900002',
                'vendor_id' => $vendor,
                'invoice_no' => 'RACE-001',
            ]);
            $this->fail('The composite database constraint must reject a concurrent duplicate.');
        } catch (QueryException $exception) {
            $this->assertSame('23000', (string) ($exception->errorInfo[0] ?? ''));
        }

        $this->assertDatabaseCount('supplier_invoices', 1);
    }

    public function test_finance_can_edit_search_and_view_a_draft_while_stale_edits_are_rejected(): void
    {
        [$finance, $vendor, $department, $category] = $this->invoiceData();
        $invoice = $this->createDraft($finance, $vendor, $department, $category, ['invoice_no' => 'SEARCH-7788']);
        $edit = $this->payload($vendor, $department, $category, [
            'invoice_no' => 'SEARCH-7788',
            'description' => 'Updated monthly service invoice.',
            'lock_version' => 1,
            'items' => [['description' => 'Monthly service', 'quantity' => '2.5000', 'unit_price' => '20.00']],
        ]);

        $this->actingAs($finance)->put(route('supplier-invoices.update', $invoice), $edit)
            ->assertRedirect(route('supplier-invoices.show', $invoice));

        $invoice->refresh();
        $this->assertSame(2, $invoice->lock_version);
        $this->assertSame('50.00', $invoice->subtotal);
        $this->assertCount(1, $invoice->items);
        $this->actingAs($finance)->get(route('supplier-invoices.show', $invoice))
            ->assertOk()
            ->assertSee('Updated monthly service invoice.');
        $this->actingAs($finance)->get(route('supplier-invoices.index', ['search' => '7788']))
            ->assertOk()
            ->assertSee($invoice->internal_no);

        $this->actingAs($finance)
            ->from(route('supplier-invoices.edit', $invoice))
            ->put(route('supplier-invoices.update', $invoice), array_merge($edit, ['description' => 'Stale overwrite']))
            ->assertRedirect(route('supplier-invoices.edit', $invoice))
            ->assertSessionHasErrors('lock_version');
        $this->assertSame('Updated monthly service invoice.', $invoice->fresh()->description);
    }

    public function test_submit_requires_an_attachment_persisted_on_that_invoice(): void
    {
        [$finance, $vendor, $department, $category] = $this->invoiceData();
        $invoice = $this->createDraft($finance, $vendor, $department, $category);

        $this->actingAs($finance)
            ->from(route('supplier-invoices.show', $invoice))
            ->post(route('supplier-invoices.submit', $invoice))
            ->assertRedirect(route('supplier-invoices.show', $invoice))
            ->assertSessionHasErrors('attachment');

        $this->assertSame(SupplierInvoiceStatus::Draft, $invoice->fresh()->status);
        $this->assertDatabaseCount('approval_instances', 0);

        $this->actingAs($finance)->post(
            route('supplier-invoice-attachments.store', $invoice),
            ['attachment' => UploadedFile::fake()->create('invoice.pdf', 80, 'application/pdf')],
        )->assertRedirect();
        $attachment = $invoice->attachments()->sole();
        Storage::disk('local')->assertExists($attachment->path);
        Storage::disk('public')->assertMissing($attachment->path);
    }

    public function test_valid_submit_recalculates_totals_and_creates_the_resolved_runtime_atomically(): void
    {
        [$finance, $vendor, $department, $category] = $this->invoiceData();
        $approver = User::factory()->create();
        [$version, $group] = $this->publishedRoute([[ApproverType::SpecificUser, (string) $approver->id, 'Invoice review']]);
        $invoice = $this->createDraft($finance, $vendor, $department, $category);
        $this->attachInvoice($invoice, $finance);
        DB::table('supplier_invoice_items')->where('supplier_invoice_id', $invoice->id)->update(['subtotal' => '999.00']);
        DB::table('supplier_invoices')->where('id', $invoice->id)->update(['subtotal' => '999.00', 'total_amount' => '999.00']);

        $this->actingAs($finance)->post(route('supplier-invoices.submit', $invoice))
            ->assertRedirect(route('supplier-invoices.show', $invoice));

        $invoice->refresh();
        $runtime = ApprovalInstance::query()->with(['steps.assignments', 'actions'])->sole();
        $this->assertSame(SupplierInvoiceStatus::InApproval, $invoice->status);
        $this->assertNotNull($invoice->submitted_at);
        $this->assertSame('12.37', $invoice->subtotal);
        $this->assertSame('12.50', $invoice->total_amount);
        $this->assertSame($version->id, $runtime->workflow_version_id);
        $this->assertSame($group->id, $runtime->workflow_rule_group_id);
        $this->assertSame([
            'module_type' => WorkflowModuleType::SupplierInvoice->value,
            'requester_id' => $finance->id,
            'department_id' => $department->id,
            'category_id' => $category->id,
            'amount' => '12.50',
            'currency' => 'MYR',
        ], $runtime->workflow_context);
        $this->assertSame(ApprovalStepStatus::Active, $runtime->steps->sole()->status);
        $this->assertSame($approver->id, $runtime->steps->sole()->assignments->sole()->approver_id);
        $this->assertSame(ApprovalAssignmentStatus::Pending, $runtime->steps->sole()->assignments->sole()->status);
        $this->assertSame(ApprovalActionType::Submitted, $runtime->actions->sole()->action);

        $this->actingAs($approver)->get(route('supplier-invoices.show', $invoice))
            ->assertOk()
            ->assertSee('Approval timeline')
            ->assertSee('Invoice review');
    }

    public function test_workflow_failure_rolls_back_recalculation_and_all_runtime_records(): void
    {
        [$finance, $vendor, $department, $category] = $this->invoiceData();
        $invoice = $this->createDraft($finance, $vendor, $department, $category);
        $this->attachInvoice($invoice, $finance);
        DB::table('supplier_invoice_items')->where('supplier_invoice_id', $invoice->id)->update(['subtotal' => '1.00']);
        DB::table('supplier_invoices')->where('id', $invoice->id)->update(['subtotal' => '1.00', 'total_amount' => '1.00']);

        $this->actingAs($finance)
            ->from(route('supplier-invoices.show', $invoice))
            ->post(route('supplier-invoices.submit', $invoice))
            ->assertRedirect(route('supplier-invoices.show', $invoice))
            ->assertSessionHasErrors('workflow');

        $invoice->refresh();
        $this->assertSame(SupplierInvoiceStatus::Draft, $invoice->status);
        $this->assertSame('1.00', $invoice->subtotal);
        $this->assertSame('1.00', $invoice->items()->firstOrFail()->subtotal);
        $this->assertDatabaseCount('approval_instances', 0);
        $this->assertDatabaseCount('approval_step_instances', 0);
        $this->assertDatabaseCount('approval_assignments', 0);
        $this->assertDatabaseCount('approval_actions', 0);
    }

    public function test_missing_approver_rolls_back_submission_without_partial_runtime(): void
    {
        [$finance, $vendor, $department, $category] = $this->invoiceData();
        $this->publishedRoute([[ApproverType::SpecificUser, '999999', 'Unavailable reviewer']]);
        $invoice = $this->createDraft($finance, $vendor, $department, $category);
        $this->attachInvoice($invoice, $finance);

        $this->actingAs($finance)
            ->from(route('supplier-invoices.show', $invoice))
            ->post(route('supplier-invoices.submit', $invoice))
            ->assertRedirect(route('supplier-invoices.show', $invoice))
            ->assertSessionHasErrors('workflow');

        $this->assertSame(SupplierInvoiceStatus::Draft, $invoice->fresh()->status);
        $this->assertDatabaseCount('approval_instances', 0);
        $this->assertDatabaseCount('approval_actions', 0);
    }

    public function test_repeated_submission_and_mutation_of_submitted_history_are_rejected(): void
    {
        [$finance, $vendor, $department, $category] = $this->invoiceData();
        $approver = User::factory()->create();
        $this->publishedRoute([[ApproverType::SpecificUser, (string) $approver->id, 'Invoice review']]);
        $invoice = $this->createDraft($finance, $vendor, $department, $category);
        $this->attachInvoice($invoice, $finance);

        $this->actingAs($finance)->post(route('supplier-invoices.submit', $invoice))->assertRedirect();
        $this->actingAs($finance)->post(route('supplier-invoices.submit', $invoice))->assertForbidden();
        $this->actingAs($finance)->put(
            route('supplier-invoices.update', $invoice),
            $this->payload($vendor, $department, $category, ['lock_version' => 2]),
        )->assertForbidden();
        $this->actingAs($finance)->delete(route('supplier-invoices.destroy', $invoice))->assertForbidden();
        $this->actingAs($finance)->post(
            route('supplier-invoice-attachments.store', $invoice),
            ['attachment' => UploadedFile::fake()->create('replacement.pdf', 10, 'application/pdf')],
        )->assertForbidden();

        $this->assertDatabaseCount('approval_instances', 1);
        $this->assertDatabaseCount('approval_actions', 1);
        $this->assertModelExists($invoice);
    }

    public function test_draft_deletion_removes_items_and_private_files_without_reusing_internal_number(): void
    {
        [$finance, $vendor, $department, $category] = $this->invoiceData();
        $invoice = $this->createDraft($finance, $vendor, $department, $category);
        $attachment = $this->attachInvoice($invoice, $finance);

        $this->actingAs($finance)->delete(route('supplier-invoices.destroy', $invoice))
            ->assertRedirect(route('supplier-invoices.index'));

        $this->assertDatabaseMissing('supplier_invoices', ['id' => $invoice->id]);
        $this->assertDatabaseMissing('supplier_invoice_items', ['supplier_invoice_id' => $invoice->id]);
        $this->assertDatabaseMissing('attachments', ['id' => $attachment->id]);
        Storage::disk('local')->assertMissing($attachment->path);

        $nextInvoice = $this->createDraft($finance, $vendor, $department, $category, ['invoice_no' => 'SUP-2026-002']);
        $this->assertStringEndsWith('000002', $nextInvoice->internal_no);
    }

    /** @return array{User, Vendor, Department, SpendCategory} */
    private function invoiceData(): array
    {
        return [
            User::factory()->create(['role' => UserRole::Finance]),
            Vendor::factory()->create(),
            Department::factory()->create(),
            SpendCategory::factory()->create(),
        ];
    }

    /** @param array<string, mixed> $overrides */
    private function createDraft(User $finance, Vendor $vendor, Department $department, SpendCategory $category, array $overrides = []): SupplierInvoice
    {
        return app(SupplierInvoiceService::class)->create(
            $this->payload($vendor, $department, $category, $overrides),
            $finance,
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(Vendor $vendor, Department $department, SpendCategory $category, array $overrides = []): array
    {
        return array_replace([
            'invoice_no' => 'SUP-2026-001',
            'vendor_id' => $vendor->id,
            'department_id' => $department->id,
            'category_id' => $category->id,
            'invoice_date' => '2026-09-01',
            'due_date' => '2026-09-30',
            'description' => 'Monthly supplier service invoice.',
            'tax_amount' => '0.13',
            'items' => [
                ['description' => 'Consulting hours', 'quantity' => '1.2345', 'unit_price' => '10.01'],
                ['description' => 'Usage adjustment', 'quantity' => '0.0001', 'unit_price' => '100.00'],
            ],
        ], $overrides);
    }

    private function attachInvoice(SupplierInvoice $invoice, User $user): Attachment
    {
        return app(AttachmentService::class)->store(
            $invoice,
            UploadedFile::fake()->create('supplier-invoice.pdf', 100, 'application/pdf'),
            $user,
        );
    }

    /**
     * @param  list<array{ApproverType, ?string, string}>  $steps
     * @return array{WorkflowVersion, WorkflowRuleGroup}
     */
    private function publishedRoute(array $steps): array
    {
        $template = WorkflowTemplate::factory()->create(['module_type' => WorkflowModuleType::SupplierInvoice]);
        $version = WorkflowVersion::factory()->create(['workflow_template_id' => $template]);
        $group = $version->ruleGroups()->create([
            'name' => 'Default supplier invoice route',
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
