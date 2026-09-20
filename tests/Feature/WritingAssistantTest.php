<?php

namespace Tests\Feature;

use App\AI\AiManager;
use App\AI\Contracts\AiProvider;
use App\AI\Testing\FakeAiProvider;
use App\ApprovalAssignmentStatus;
use App\ApprovalStepStatus;
use App\Models\AiInteraction;
use App\Models\ApprovalAssignment;
use App\Models\ApprovalInstance;
use App\Models\ApprovalStepInstance;
use App\Models\Department;
use App\Models\ExpenseClaim;
use App\Models\PurchaseRequest;
use App\Models\User;
use App\PurchaseRequestStatus;
use App\Services\WritingAssistantService;
use App\WorkflowModuleType;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WritingAssistantTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_purchase_request_and_expense_claim_drafts_are_preview_only_and_escaped(): void
    {
        $department = Department::factory()->create();
        $user = User::factory()->create(['department_id' => $department]);
        $fake = FakeAiProvider::respondingWith('{"draft_text":"Finance & Operations justification."}', '{"draft_text":"Clear client travel context."}', '{"draft_text":"<script>alert(1)</script>"}');
        $this->useFake($fake);

        $createUrl = route('purchase-requests.create');
        $this->actingAs($user)->from($createUrl)->post(route('purchase-requests.writing-assistance.create'), ['title' => 'Laptop refresh', 'description' => 'Need laptops', 'items' => [['description' => 'Laptop']]])
            ->assertRedirect($createUrl)->assertSessionHas('writingAssistance.draft_text', 'Finance & Operations justification.');
        $this->get($createUrl)->assertOk()->assertSee('Finance &amp; Operations justification.', false)->assertSee('Apply suggestion')->assertSee('Ignore');
        $this->actingAs($user)->post(route('expense-claims.writing-assistance.create'), ['title' => 'Client travel', 'description' => 'Trip', 'items' => [['merchant' => 'Rail Link', 'description' => 'Train']]])
            ->assertSessionHas('writingAssistance.draft_text', 'Clear client travel context.')
            ->assertSessionHasInput('description', 'Trip');
        $this->actingAs($user)->post(route('purchase-requests.writing-assistance.create'), ['title' => 'Laptop refresh', 'description' => 'Changed source'])
            ->assertSessionHas('writingAssistanceError');

        $this->assertDatabaseCount('purchase_requests', 0);
        $this->assertDatabaseCount('expense_claims', 0);
        $this->assertSame(WritingAssistantService::PURCHASE_REQUEST_FEATURE, AiInteraction::query()->oldest('id')->value('feature'));
        $this->assertStringContainsString('untrusted DATA', $fake->requests()[0]->prompt);
    }

    public function test_request_changes_and_rejection_drafts_require_current_actionable_assignment_and_create_no_actions(): void
    {
        [$assignment, $approver] = $this->assignment();
        $fake = FakeAiProvider::respondingWith('{"draft_text":"Please attach the missing quotation."}', '{"draft_text":"The documented budget is unavailable."}');
        $this->useFake($fake);
        $actionCount = DB::table('approval_actions')->count();

        $this->actingAs($approver)->post(route('approval-assignments.writing-assistance', [$assignment, 'request_changes']), ['comment' => 'Need quote'])
            ->assertSessionHas('writingAssistance.target', 'changes-comment');
        $this->actingAs($approver)->post(route('approval-assignments.writing-assistance', [$assignment, 'reject']), ['comment' => 'Budget issue'])
            ->assertSessionHas('writingAssistance.target', 'reject-comment');
        $this->assertSame($actionCount, DB::table('approval_actions')->count());
        $this->assertSame(ApprovalAssignmentStatus::Pending, $assignment->fresh()->status);
        $this->assertEqualsCanonicalizing([WritingAssistantService::REQUEST_CHANGES_FEATURE, WritingAssistantService::REJECTION_FEATURE], AiInteraction::query()->pluck('feature')->all());

        $assignment->update(['status' => ApprovalAssignmentStatus::Skipped]);
        $this->actingAs($approver)->post(route('approval-assignments.writing-assistance', [$assignment, 'reject']))->assertSessionHasErrors('action');
    }

    public function test_disabled_ai_does_not_break_forms_or_persist_text(): void
    {
        $user = User::factory()->for(Department::factory())->create();
        config()->set('ai.enabled', false);
        $this->app->forgetInstance(AiManager::class);
        $this->actingAs($user)->post(route('purchase-requests.writing-assistance.create'), ['description' => 'Original wording'])
            ->assertSessionHas('writingAssistanceError')->assertSessionHasInput('description', 'Original wording');
        $this->actingAs($user)->get(route('purchase-requests.create'))->assertOk()->assertDontSee('Improve with AI');
        $this->assertDatabaseCount('purchase_requests', 0);
    }

    public function test_existing_record_assistance_requires_update_authority(): void
    {
        $department = Department::factory()->create();
        $owner = User::factory()->create(['department_id' => $department]);
        $other = User::factory()->for(Department::factory())->create();
        $purchaseRequest = PurchaseRequest::factory()->create(['requester_id' => $owner, 'department_id' => $department]);
        $expenseClaim = ExpenseClaim::factory()->create(['employee_id' => $owner, 'department_id' => $department]);
        $this->useFake(FakeAiProvider::respondingWith('{"draft_text":"Improved request context."}', '{"draft_text":"Improved claim context."}'));

        $this->actingAs($owner)->put(route('purchase-requests.writing-assistance.update', $purchaseRequest), ['description' => 'Request context'])->assertSessionHas('writingAssistance');
        $this->actingAs($owner)->put(route('expense-claims.writing-assistance.update', $expenseClaim), ['description' => 'Claim context'])->assertSessionHas('writingAssistance');
        $this->actingAs($other)->put(route('purchase-requests.writing-assistance.update', $purchaseRequest), ['description' => 'Tampered'])->assertForbidden();
        $this->actingAs($other)->put(route('expense-claims.writing-assistance.update', $expenseClaim), ['description' => 'Tampered'])->assertForbidden();
    }

    /** @return array{ApprovalAssignment, User} */
    private function assignment(): array
    {
        $department = Department::factory()->create();
        $requester = User::factory()->create(['department_id' => $department]);
        $approver = User::factory()->create();
        $record = PurchaseRequest::factory()->create(['requester_id' => $requester, 'department_id' => $department, 'status' => PurchaseRequestStatus::Draft]);
        DB::table('purchase_requests')->where('id', $record->id)->update(['status' => PurchaseRequestStatus::InApproval->value]);
        $instance = ApprovalInstance::factory()->create(['approvable_type' => PurchaseRequest::class, 'approvable_id' => $record->id, 'workflow_context' => ['module_type' => WorkflowModuleType::PurchaseRequest->value, 'requester_id' => $requester->id, 'department_id' => $department->id, 'category_id' => $record->category_id, 'amount' => $record->total_amount, 'currency' => $record->currency]]);
        $step = ApprovalStepInstance::factory()->for($instance, 'approvalInstance')->create(['status' => ApprovalStepStatus::Active]);

        return [ApprovalAssignment::factory()->for($step, 'step')->create(['approver_id' => $approver, 'status' => ApprovalAssignmentStatus::Pending]), $approver];
    }

    private function useFake(FakeAiProvider $fake): void
    {
        config()->set(['ai.enabled' => true, 'ai.provider' => 'fake', 'ai.model' => 'fake-ai09']);
        $this->app->instance(AiProvider::class, $fake);
        $this->app->forgetInstance(AiManager::class);
    }
}
