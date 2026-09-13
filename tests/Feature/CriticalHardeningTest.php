<?php

namespace Tests\Feature;

use App\ApprovalInstanceStatus;
use App\ApprovalStepStatus;
use App\Models\ApprovalAssignment;
use App\Models\ApprovalInstance;
use App\Models\ApprovalStepInstance;
use App\Models\Department;
use App\Models\ExpenseClaim;
use App\Models\ExpenseItem;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestItem;
use App\Models\SupplierInvoice;
use App\Models\User;
use App\PurchaseRequestStatus;
use App\Services\AttachmentService;
use App\Services\PurchaseRequestService;
use App\Services\SupplierInvoiceService;
use App\UserRole;
use App\UserStatus;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CriticalHardeningTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_expense_receipt_mutation_locks_the_claim_before_its_item(): void
    {
        Storage::fake('local');
        $employee = User::factory()->create(['department_id' => Department::factory()]);
        $claim = ExpenseClaim::factory()->create([
            'employee_id' => $employee,
            'department_id' => $employee->department_id,
        ]);
        $item = ExpenseItem::factory()->create(['expense_claim_id' => $claim]);
        $item->setRelation('expenseClaim', $claim);
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        app(AttachmentService::class)->store(
            $item,
            UploadedFile::fake()->create('receipt.pdf', 10, 'application/pdf'),
            $employee,
        );

        $claimQuery = collect($queries)->search(fn (string $sql): bool => str_contains($sql, 'expense_claims'));
        $itemQuery = collect($queries)->search(fn (string $sql): bool => str_contains($sql, 'expense_items'));
        $this->assertIsInt($claimQuery);
        $this->assertIsInt($itemQuery);
        $this->assertLessThan($itemQuery, $claimQuery);
        $this->assertDatabaseCount('attachments', 1);
    }

    public function test_attachment_persistence_rechecks_a_stale_users_active_state(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create(['department_id' => Department::factory()]);
        $purchaseRequest = PurchaseRequest::factory()->create([
            'requester_id' => $owner,
            'department_id' => $owner->department_id,
        ]);
        DB::table('users')->where('id', $owner->id)->update(['status' => UserStatus::Inactive->value]);

        try {
            app(AttachmentService::class)->store(
                $purchaseRequest,
                UploadedFile::fake()->create('quote.pdf', 10, 'application/pdf'),
                $owner,
            );
            $this->fail('A stale active user must not be able to persist an attachment.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('attachments', 0);
            $this->assertSame([], Storage::disk('local')->allFiles());
        }
    }

    public function test_draft_mutation_rechecks_a_stale_finance_users_authority(): void
    {
        $finance = User::factory()->create(['role' => UserRole::Finance]);
        $invoice = SupplierInvoice::factory()->create(['submitted_by' => $finance]);
        DB::table('users')->where('id', $finance->id)->update(['status' => UserStatus::Inactive->value]);

        try {
            app(SupplierInvoiceService::class)->deleteDraft($invoice, $finance);
            $this->fail('A stale Finance user must not be able to delete an invoice draft.');
        } catch (AuthorizationException) {
            $this->assertModelExists($invoice);
        }
    }

    public function test_purchase_request_mutation_rechecks_a_stale_owners_authority(): void
    {
        $owner = User::factory()->create(['department_id' => Department::factory()]);
        $purchaseRequest = PurchaseRequest::factory()->create([
            'requester_id' => $owner,
            'department_id' => $owner->department_id,
        ]);
        DB::table('users')->where('id', $owner->id)->update(['status' => UserStatus::Inactive->value]);

        try {
            app(PurchaseRequestService::class)->deleteDraft($purchaseRequest, $owner);
            $this->fail('A stale owner must not be able to delete a purchase request draft.');
        } catch (AuthorizationException) {
            $this->assertModelExists($purchaseRequest);
        }
    }

    public function test_inactive_users_fail_closed_at_the_purchase_request_policy_boundary(): void
    {
        $owner = User::factory()->inactive()->create(['department_id' => Department::factory()]);
        $purchaseRequest = PurchaseRequest::factory()->create([
            'requester_id' => $owner,
            'department_id' => $owner->department_id,
        ]);

        $this->assertFalse(Gate::forUser($owner)->allows('view', $purchaseRequest));
        $this->assertFalse(Gate::forUser($owner)->allows('update', $purchaseRequest));
        $this->assertFalse(Gate::forUser($owner)->allows('delete', $purchaseRequest));
    }

    public function test_stale_pending_assignment_does_not_grant_business_record_visibility(): void
    {
        $owner = User::factory()->create(['department_id' => Department::factory()]);
        $staleApprover = User::factory()->create();
        $purchaseRequest = PurchaseRequest::factory()->create([
            'requester_id' => $owner,
            'department_id' => $owner->department_id,
            'status' => PurchaseRequestStatus::Approved,
            'title' => 'Private completed request',
        ]);
        $instance = ApprovalInstance::factory()->create([
            'approvable_type' => $purchaseRequest->getMorphClass(),
            'approvable_id' => $purchaseRequest->id,
            'status' => ApprovalInstanceStatus::Approved,
        ]);
        $step = ApprovalStepInstance::factory()->create([
            'approval_instance_id' => $instance,
            'status' => ApprovalStepStatus::Completed,
        ]);
        ApprovalAssignment::factory()->create([
            'approval_step_instance_id' => $step,
            'approver_id' => $staleApprover,
        ]);

        $this->assertFalse(Gate::forUser($staleApprover)->allows('view', $purchaseRequest));
        $this->actingAs($staleApprover)
            ->get(route('purchase-requests.show', $purchaseRequest))
            ->assertForbidden();
        $this->actingAs($staleApprover)
            ->get(route('purchase-requests.index'))
            ->assertOk()
            ->assertDontSee('Private completed request');
    }

    public function test_business_record_views_escape_free_text_fields(): void
    {
        $owner = User::factory()->create(['department_id' => Department::factory()]);
        $dangerous = '<script>alert("opsflow")</script>';
        $purchaseRequest = PurchaseRequest::factory()->create([
            'requester_id' => $owner,
            'department_id' => $owner->department_id,
            'title' => $dangerous,
            'description' => $dangerous,
        ]);
        PurchaseRequestItem::factory()->create([
            'purchase_request_id' => $purchaseRequest,
            'description' => $dangerous,
        ]);

        $this->actingAs($owner)->get(route('purchase-requests.show', $purchaseRequest))
            ->assertSee($dangerous)
            ->assertDontSee($dangerous, false);
    }
}
