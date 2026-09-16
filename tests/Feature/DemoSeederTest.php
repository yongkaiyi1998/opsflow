<?php

namespace Tests\Feature;

use App\ApprovalActionType;
use App\ApprovalAssignmentStatus;
use App\Models\ApprovalAction;
use App\Models\ApprovalAssignment;
use App\Models\Attachment;
use App\Models\ExpenseClaim;
use App\Models\PurchaseRequest;
use App\Models\SupplierInvoice;
use App\Models\User;
use App\Models\WorkflowTemplate;
use App\PurchaseRequestStatus;
use App\SupplierInvoiceStatus;
use App\WorkflowVersionStatus;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DemoSeederTest extends TestCase
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

    public function test_demo_seed_is_reproducible_and_covers_the_v1_walkthrough(): void
    {
        Storage::fake('local');

        $this->seed(DemoSeeder::class);
        $this->seed(DemoSeeder::class);

        $admin = User::query()->where('email', 'admin@opsflow.test')->firstOrFail();
        $this->assertTrue(Hash::check('OpsFlowDemo!', $admin->password));
        $this->assertSame(8, User::query()->where('email', 'like', '%@opsflow.test')->count());
        $this->assertSame(3, WorkflowTemplate::query()->whereIn('code', [
            'PR-APPROVAL', 'INV-APPROVAL', 'EXP-APPROVAL',
        ])->count());
        $this->assertSame(3, WorkflowTemplate::query()
            ->whereHas('versions', fn ($query) => $query->where('status', WorkflowVersionStatus::Published->value))
            ->count());

        $this->assertDatabaseHas('workflow_rule_groups', ['name' => 'IT High Value', 'priority' => 10]);
        $this->assertDatabaseHas('workflow_rule_groups', ['name' => 'Standard High Value', 'priority' => 20]);
        $this->assertDatabaseHas('workflow_rule_groups', ['name' => 'Director for High Value', 'priority' => 10]);
        $this->assertDatabaseHas('workflow_steps', ['name' => 'Requester manager', 'step_order' => 1]);
        $this->assertDatabaseHas('workflow_steps', ['name' => 'Finance review', 'step_order' => 2]);

        $this->assertSame(PurchaseRequestStatus::ChangesRequested, PurchaseRequest::query()
            ->where('title', 'Developer conference passes')->firstOrFail()->status);
        $this->assertSame(PurchaseRequestStatus::Withdrawn, PurchaseRequest::query()
            ->where('title', 'Temporary design software licences')->firstOrFail()->status);
        $this->assertSame(SupplierInvoiceStatus::Approved, SupplierInvoice::query()
            ->where('invoice_no', 'NC-2026-0815')->firstOrFail()->status);
        $this->assertTrue(ExpenseClaim::query()->where('title', 'Cloud architecture workshop')->exists());

        $this->assertTrue(ApprovalAction::query()->where('action', ApprovalActionType::ChangesRequested->value)->exists());
        $this->assertTrue(ApprovalAction::query()->where('action', ApprovalActionType::Resubmitted->value)->exists());
        $this->assertTrue(ApprovalAction::query()->where('action', ApprovalActionType::Withdrawn->value)->exists());
        $this->assertTrue(ApprovalAssignment::query()->where('status', ApprovalAssignmentStatus::Pending->value)->exists());
        $this->assertGreaterThanOrEqual(9, Attachment::query()->count());
        $this->assertDatabaseCount('activity_logs', 4);
        $this->assertGreaterThan(0, User::query()->where('email', 'employee@opsflow.test')->firstOrFail()->notifications()->count());
        $this->assertDatabaseCount('users', 8);

        $this->actingAs($admin)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Manage workflows');
        $this->actingAs(User::query()->where('email', 'analyst@opsflow.test')->firstOrFail())
            ->get(route('supplier-invoices.index'))
            ->assertOk()
            ->assertSee('All departments')
            ->assertSee('All categories');
        $this->actingAs(User::query()->where('email', 'employee@opsflow.test')->firstOrFail())
            ->get(route('purchase-requests.index'))
            ->assertOk()
            ->assertSee('status-badge-warning', false)
            ->assertSee('status-badge-success', false);
    }
}
