<?php

namespace Tests\Feature;

use App\AI\AiManager;
use App\AI\Contracts\AiProvider;
use App\AI\Testing\FakeAiProvider;
use App\AiInteractionStatus;
use App\ApprovalAssignmentStatus;
use App\ApprovalStepStatus;
use App\Models\AiInteraction;
use App\Models\ApprovalAssignment;
use App\Models\ApprovalInstance;
use App\Models\ApprovalStepInstance;
use App\Models\Department;
use App\Models\PurchaseRequest;
use App\Models\User;
use App\PurchaseRequestStatus;
use App\Services\WorkflowExplanationService;
use App\WorkflowModuleType;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WorkflowExplanationTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_explanation_is_grounded_in_authoritative_route_and_does_not_modify_workflow(): void
    {
        [$assignment, $approver, $instance] = $this->assignment();
        $instance->load('workflowVersion.template', 'workflowRuleGroup', 'steps');
        $payload = $this->validPayload($assignment, $instance);
        $fake = FakeAiProvider::respondingWith(json_encode($payload, JSON_THROW_ON_ERROR));
        $this->useFake($fake);
        $before = [$instance->fresh()->toArray(), $assignment->fresh()->toArray(), DB::table('approval_actions')->count()];

        $this->actingAs($approver)->post(route('approval-assignments.workflow-explanation', $assignment))->assertSessionHas('success');

        $interaction = AiInteraction::query()->sole();
        $this->assertSame(WorkflowExplanationService::FEATURE, $interaction->feature);
        $this->assertSame(AiInteractionStatus::Succeeded, $interaction->status);
        $this->assertStringContainsString($instance->workflowRuleGroup->name, $fake->requests()[0]->prompt);
        $this->assertStringContainsString($assignment->step->name, $fake->requests()[0]->prompt);
        $this->assertStringNotContainsString($approver->email, $fake->requests()[0]->prompt);
        $this->assertEquals($before, [$instance->fresh()->toArray(), $assignment->fresh()->toArray(), DB::table('approval_actions')->count()]);
    }

    public function test_disabled_ai_keeps_useful_deterministic_fallback_on_review(): void
    {
        [$assignment, $approver, $instance] = $this->assignment();
        config()->set('ai.enabled', false);

        $this->actingAs($approver)->get(route('approvals.show', $assignment))
            ->assertOk()->assertSee('Why am I approving this?')
            ->assertSee($instance->workflowRuleGroup->name)->assertSee($assignment->step->name)
            ->assertDontSee('Explain with AI');
        $this->assertDatabaseCount('ai_interactions', 0);
    }

    public function test_invented_route_recommendation_malformed_and_stale_requests_fail_safely(): void
    {
        [$assignment, $approver, $instance] = $this->assignment();
        $bad = $this->validPayload($assignment, $instance);
        $bad['rule_group'] = 'Invented executive route';
        $recommendation = $this->validPayload($assignment, $instance);
        $recommendation['explanation'] = 'You should approve this request.';
        $this->useFake(FakeAiProvider::respondingWith(json_encode($bad, JSON_THROW_ON_ERROR), json_encode($recommendation, JSON_THROW_ON_ERROR), 'not-json'));
        $this->actingAs($approver)->post(route('approval-assignments.workflow-explanation', $assignment))->assertSessionHas('workflowExplanationError');
        $this->actingAs($approver)->post(route('approval-assignments.workflow-explanation', $assignment))->assertSessionHas('workflowExplanationError');
        $this->actingAs($approver)->post(route('approval-assignments.workflow-explanation', $assignment))->assertSessionHas('workflowExplanationError');
        $this->assertSame(AiInteractionStatus::Failed, AiInteraction::query()->sole()->status);

        $assignment->update(['status' => ApprovalAssignmentStatus::Skipped]);
        $this->actingAs($approver)->post(route('approval-assignments.workflow-explanation', $assignment))->assertSessionHasErrors('action');
        $this->actingAs(User::factory()->create())->post(route('approval-assignments.workflow-explanation', $assignment))->assertForbidden();
    }

    /** @return array{ApprovalAssignment, User, ApprovalInstance} */
    private function assignment(): array
    {
        $department = Department::factory()->create(['name' => 'Engineering']);
        $requester = User::factory()->create(['department_id' => $department]);
        $approver = User::factory()->create();
        $record = PurchaseRequest::factory()->create(['requester_id' => $requester, 'department_id' => $department, 'status' => PurchaseRequestStatus::Draft, 'total_amount' => '12500.00']);
        DB::table('purchase_requests')->where('id', $record->id)->update(['status' => PurchaseRequestStatus::InApproval->value]);
        $instance = ApprovalInstance::factory()->create(['approvable_type' => PurchaseRequest::class, 'approvable_id' => $record->id, 'workflow_context' => ['module_type' => WorkflowModuleType::PurchaseRequest->value, 'requester_id' => $requester->id, 'department_id' => $department->id, 'category_id' => $record->category_id, 'amount' => '12500.00', 'currency' => 'MYR']]);
        $step = ApprovalStepInstance::factory()->for($instance, 'approvalInstance')->create(['name' => 'Finance review', 'status' => ApprovalStepStatus::Active]);
        $assignment = ApprovalAssignment::factory()->for($step, 'step')->create(['approver_id' => $approver, 'status' => ApprovalAssignmentStatus::Pending]);

        return [$assignment->load('step'), $approver, $instance->fresh()];
    }

    /** @return array<string, mixed> */
    private function validPayload(ApprovalAssignment $assignment, ApprovalInstance $instance): array
    {
        $instance->loadMissing('workflowVersion', 'workflowRuleGroup');

        return ['workflow_version' => $instance->workflowVersion->version, 'rule_group' => $instance->workflowRuleGroup->name, 'current_step' => $assignment->step->name, 'assignment_basis' => $assignment->step->approver_type->label(), 'headline' => 'Your current workflow assignment', 'explanation' => 'This request follows the persisted route and is at your active step.', 'key_points' => ['The configured step is currently active.']];
    }

    private function useFake(FakeAiProvider $fake): void
    {
        config()->set(['ai.enabled' => true, 'ai.provider' => 'fake', 'ai.model' => 'fake-ai09']);
        $this->app->instance(AiProvider::class, $fake);
        $this->app->forgetInstance(AiManager::class);
    }
}
