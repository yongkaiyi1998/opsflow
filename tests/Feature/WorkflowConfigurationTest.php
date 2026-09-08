<?php

namespace Tests\Feature;

use App\ApprovalMode;
use App\ApproverType;
use App\MasterDataStatus;
use App\Models\Department;
use App\Models\User;
use App\Models\WorkflowRule;
use App\Models\WorkflowRuleGroup;
use App\Models\WorkflowStep;
use App\Models\WorkflowTemplate;
use App\Models\WorkflowVersion;
use App\Services\WorkflowConfigurationService;
use App\WorkflowModuleType;
use App\WorkflowRuleField;
use App\WorkflowRuleOperator;
use App\WorkflowVersionStatus;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class WorkflowConfigurationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_admin_can_create_and_update_a_template_with_an_initial_draft(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->post(route('workflow-templates.store'), [
            'name' => 'Invoice Approval',
            'code' => ' inv-main ',
            'module_type' => WorkflowModuleType::SupplierInvoice->value,
            'description' => 'Supplier invoice routing.',
            'status' => MasterDataStatus::Active->value,
        ]);

        $template = WorkflowTemplate::firstOrFail();
        $response->assertRedirect(route('workflow-templates.show', $template));
        $this->assertSame('INV-MAIN', $template->code);
        $this->assertDatabaseHas('workflow_versions', [
            'workflow_template_id' => $template->id,
            'version' => 1,
            'status' => WorkflowVersionStatus::Draft->value,
            'created_by' => $admin->id,
        ]);

        $this->actingAs($admin)->put(route('workflow-templates.update', $template), [
            'name' => 'Invoice Review',
            'code' => 'INV-MAIN',
            'module_type' => WorkflowModuleType::SupplierInvoice->value,
            'description' => null,
            'status' => MasterDataStatus::Inactive->value,
        ])->assertRedirect(route('workflow-templates.show', $template));

        $this->assertDatabaseHas('workflow_templates', ['id' => $template->id, 'name' => 'Invoice Review', 'status' => 'INACTIVE']);
    }

    public function test_non_admin_cannot_access_or_mutate_workflow_configuration(): void
    {
        $employee = User::factory()->create();
        [$version, $group] = $this->validDraft(User::factory()->admin()->create());

        $this->actingAs($employee)->get(route('workflow-templates.index'))->assertForbidden();
        $this->actingAs($employee)->get(route('workflow-versions.edit', $version))->assertForbidden();
        $this->actingAs($employee)->post(route('workflow-rule-groups.store', $version), [])->assertForbidden();
        $this->actingAs($employee)->post(route('workflow-versions.publish', $version))->assertForbidden();
        $this->actingAs($employee)->delete(route('workflow-rule-groups.destroy', $group))->assertForbidden();
    }

    public function test_admin_can_configure_rule_groups_rules_and_ordered_steps(): void
    {
        $admin = User::factory()->admin()->create();
        $version = WorkflowVersion::factory()->create(['created_by' => $admin]);

        $this->actingAs($admin)->post(route('workflow-rule-groups.store', $version), [
            'name' => 'High value', 'priority' => 10, 'is_default' => false,
        ])->assertSessionHasNoErrors();
        $group = WorkflowRuleGroup::firstOrFail();

        $this->actingAs($admin)->post(route('workflow-rules.store', $group), [
            'field' => WorkflowRuleField::Amount->value,
            'operator' => WorkflowRuleOperator::GreaterThanOrEqual->value,
            'value' => '1000.005',
        ])->assertSessionHasNoErrors();

        $this->actingAs($admin)->post(route('workflow-steps.store', $group), [
            'step_order' => 1,
            'name' => 'Finance review',
            'approver_type' => ApproverType::Role->value,
            'approver_value' => 'FINANCE',
            'approval_mode' => ApprovalMode::Any->value,
            'sla_hours' => 24,
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('workflow_rules', ['workflow_rule_group_id' => $group->id, 'field' => 'amount', 'operator' => '>=']);
        $this->assertSame('1000.01', WorkflowRule::firstOrFail()->value);
        $this->assertDatabaseHas('workflow_steps', ['workflow_rule_group_id' => $group->id, 'step_order' => 1, 'approver_value' => 'FINANCE']);
    }

    public function test_admin_can_update_and_remove_draft_configuration(): void
    {
        $admin = User::factory()->admin()->create();
        [$version, $group, $rule, $step] = $this->validDraft($admin);

        $this->actingAs($admin)->put(route('workflow-rule-groups.update', $group), [
            'name' => 'Priority route', 'priority' => 5, 'is_default' => false,
        ])->assertSessionHasNoErrors();
        $this->actingAs($admin)->put(route('workflow-rules.update', $rule), [
            'field' => WorkflowRuleField::Amount->value,
            'operator' => WorkflowRuleOperator::GreaterThan->value,
            'value' => '5000',
        ])->assertSessionHasNoErrors();
        $this->actingAs($admin)->put(route('workflow-steps.update', $step), [
            'step_order' => 1,
            'name' => 'Finance approval',
            'approver_type' => ApproverType::Role->value,
            'approver_value' => 'FINANCE',
            'approval_mode' => ApprovalMode::Any->value,
            'sla_hours' => null,
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('workflow_rule_groups', ['id' => $group->id, 'name' => 'Priority route', 'priority' => 5]);
        $this->assertSame('5000.00', $rule->refresh()->value);
        $this->assertDatabaseHas('workflow_steps', ['id' => $step->id, 'name' => 'Finance approval', 'approver_value' => 'FINANCE']);

        $this->actingAs($admin)->delete(route('workflow-rules.destroy', $rule))->assertSessionHasNoErrors();
        $this->actingAs($admin)->delete(route('workflow-steps.destroy', $step))->assertSessionHasNoErrors();
        $this->actingAs($admin)->delete(route('workflow-rule-groups.destroy', $group))->assertSessionHasNoErrors();
        $this->assertSame(0, $version->ruleGroups()->count());
    }

    public function test_rule_validation_rejects_unsupported_operators_and_missing_references(): void
    {
        $admin = User::factory()->admin()->create();
        $group = WorkflowRuleGroup::factory()->create(['workflow_version_id' => WorkflowVersion::factory()->create(['created_by' => $admin])]);

        $this->actingAs($admin)->post(route('workflow-rules.store', $group), [
            'field' => WorkflowRuleField::Department->value,
            'operator' => WorkflowRuleOperator::GreaterThan->value,
            'value' => '1',
        ])->assertSessionHasErrors('operator');

        $this->actingAs($admin)->post(route('workflow-rules.store', $group), [
            'field' => WorkflowRuleField::Department->value,
            'operator' => WorkflowRuleOperator::In->value,
            'value' => '999998,999999',
        ])->assertSessionHasErrors('value');

        $this->assertDatabaseCount('workflow_rules', 0);
    }

    public function test_database_failures_during_rule_validation_propagate(): void
    {
        $admin = User::factory()->admin()->create();
        [$version] = $this->validDraft($admin);
        Schema::drop('departments');

        $this->expectException(QueryException::class);
        app(WorkflowConfigurationService::class)->publish($version, $admin);
    }

    public function test_expected_invalid_rule_values_remain_publication_validation_errors(): void
    {
        $admin = User::factory()->admin()->create();
        [$version, , $rule] = $this->validDraft($admin);
        $rule->update([
            'field' => WorkflowRuleField::Amount,
            'operator' => WorkflowRuleOperator::GreaterThan,
            'value' => '10000000000000.00',
        ]);

        $this->actingAs($admin)->post(route('workflow-versions.publish', $version))
            ->assertSessionHasErrors('workflow');

        $this->assertSame(WorkflowVersionStatus::Draft, $version->refresh()->status);
    }

    public function test_valid_draft_publishes_and_records_business_audit(): void
    {
        $admin = User::factory()->admin()->create();
        [$version] = $this->validDraft($admin);

        $this->actingAs($admin)->post(route('workflow-versions.publish', $version))->assertSessionHasNoErrors();

        $version->refresh();
        $this->assertSame(WorkflowVersionStatus::Published, $version->status);
        $this->assertSame($admin->id, $version->published_by);
        $this->assertNotNull($version->published_at);
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'WORKFLOW_VERSION_PUBLISHED',
            'subject_type' => WorkflowVersion::class,
            'subject_id' => $version->id,
            'user_id' => $admin->id,
        ]);
    }

    public function test_invalid_draft_cannot_be_published(): void
    {
        $admin = User::factory()->admin()->create();
        $version = WorkflowVersion::factory()->create(['created_by' => $admin]);

        $this->actingAs($admin)->from(route('workflow-versions.edit', $version))
            ->post(route('workflow-versions.publish', $version))
            ->assertRedirect(route('workflow-versions.edit', $version))
            ->assertSessionHasErrors('workflow');

        $this->assertSame(WorkflowVersionStatus::Draft, $version->refresh()->status);
        $this->assertDatabaseCount('activity_logs', 0);
    }

    public function test_publish_requires_rules_steps_and_sequential_step_order(): void
    {
        $admin = User::factory()->admin()->create();
        $version = WorkflowVersion::factory()->create(['created_by' => $admin]);
        $group = WorkflowRuleGroup::factory()->create(['workflow_version_id' => $version]);
        WorkflowStep::factory()->create(['workflow_rule_group_id' => $group, 'step_order' => 2]);

        $this->actingAs($admin)->post(route('workflow-versions.publish', $version))->assertSessionHasErrors('workflow');
        $this->assertSame(WorkflowVersionStatus::Draft, $version->refresh()->status);
    }

    public function test_publishing_new_version_archives_previous_current_version(): void
    {
        $admin = User::factory()->admin()->create();
        [$versionOne] = $this->validDraft($admin);
        app(WorkflowConfigurationService::class)->publish($versionOne, $admin);
        [$versionTwo] = $this->validDraft($admin, $versionOne->template, 2);

        app(WorkflowConfigurationService::class)->publish($versionTwo, $admin);

        $this->assertSame(WorkflowVersionStatus::Archived, $versionOne->refresh()->status);
        $this->assertNotNull($versionOne->effective_until);
        $this->assertSame(WorkflowVersionStatus::Published, $versionTwo->refresh()->status);
        $this->assertSame(1, WorkflowVersion::where('workflow_template_id', $versionOne->workflow_template_id)->where('status', 'PUBLISHED')->count());
    }

    public function test_published_configuration_is_immutable_through_every_mutation_route(): void
    {
        $admin = User::factory()->admin()->create();
        [$version, $group, $rule, $step] = $this->validDraft($admin);
        app(WorkflowConfigurationService::class)->publish($version, $admin);

        $this->actingAs($admin)->put(route('workflow-rule-groups.update', $group), ['name' => 'Changed', 'priority' => 1, 'is_default' => false])->assertForbidden();
        $this->actingAs($admin)->put(route('workflow-rules.update', $rule), ['field' => 'amount', 'operator' => '>', 'value' => '1'])->assertForbidden();
        $this->actingAs($admin)->put(route('workflow-steps.update', $step), [])->assertForbidden();
        $this->actingAs($admin)->delete(route('workflow-rule-groups.destroy', $group))->assertForbidden();
        $this->actingAs($admin)->delete(route('workflow-rules.destroy', $rule))->assertForbidden();
        $this->actingAs($admin)->delete(route('workflow-steps.destroy', $step))->assertForbidden();
        $this->actingAs($admin)->delete(route('workflow-versions.destroy', $version))->assertForbidden();

        $this->assertDatabaseHas('workflow_rule_groups', ['id' => $group->id, 'name' => $group->name]);
        $this->assertDatabaseHas('workflow_rules', ['id' => $rule->id]);
        $this->assertDatabaseHas('workflow_steps', ['id' => $step->id]);
    }

    public function test_child_mutation_rechecks_locked_version_state_instead_of_trusting_stale_model(): void
    {
        $admin = User::factory()->admin()->create();
        $version = WorkflowVersion::factory()->create(['created_by' => $admin]);
        WorkflowVersion::whereKey($version->id)->update(['status' => WorkflowVersionStatus::Published->value]);

        try {
            app(WorkflowConfigurationService::class)->saveRuleGroup($version, [
                'name' => 'Too late', 'priority' => 10, 'is_default' => false,
            ], $admin);
            $this->fail('Expected the locked version check to reject a stale draft.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('workflow_rule_groups', 0);
        }
    }

    public function test_model_layer_rejects_direct_changes_to_published_configuration(): void
    {
        $admin = User::factory()->admin()->create();
        [$version, $group] = $this->validDraft($admin);
        app(WorkflowConfigurationService::class)->publish($version, $admin);

        $this->expectException(\LogicException::class);
        $group->update(['name' => 'Changed outside the service']);
    }

    public function test_clone_copies_published_configuration_into_next_draft_without_changing_source(): void
    {
        $admin = User::factory()->admin()->create();
        [$published, $sourceGroup] = $this->validDraft($admin);
        app(WorkflowConfigurationService::class)->publish($published, $admin);

        $this->actingAs($admin)->post(route('workflow-versions.clone', $published))->assertSessionHasNoErrors();

        $clone = WorkflowVersion::where('workflow_template_id', $published->workflow_template_id)->where('status', 'DRAFT')->firstOrFail();
        $this->assertSame(2, $clone->version);
        $this->assertSame(WorkflowVersionStatus::Published, $published->refresh()->status);
        $this->assertSame($sourceGroup->name, $clone->ruleGroups->first()->name);
        $this->assertCount(1, $clone->ruleGroups->first()->rules);
        $this->assertCount(1, $clone->ruleGroups->first()->steps);
        $this->assertNotSame($sourceGroup->id, $clone->ruleGroups->first()->id);
    }

    public function test_only_one_default_rule_group_is_allowed(): void
    {
        $admin = User::factory()->admin()->create();
        $version = WorkflowVersion::factory()->create(['created_by' => $admin]);
        WorkflowRuleGroup::factory()->create(['workflow_version_id' => $version, 'is_default' => true]);

        $this->actingAs($admin)->post(route('workflow-rule-groups.store', $version), [
            'name' => 'Another default', 'priority' => 20, 'is_default' => true,
        ])->assertSessionHasErrors('is_default');

        $this->assertSame(1, $version->ruleGroups()->where('is_default', true)->count());
    }

    public function test_draft_versions_can_be_deleted_but_published_versions_are_preserved(): void
    {
        $admin = User::factory()->admin()->create();
        [$draft] = $this->validDraft($admin);
        $template = $draft->template;

        $this->actingAs($admin)->delete(route('workflow-versions.destroy', $draft))->assertRedirect(route('workflow-templates.show', $template));
        $this->assertDatabaseMissing('workflow_versions', ['id' => $draft->id]);

        [$published] = $this->validDraft($admin, $template, 2);
        app(WorkflowConfigurationService::class)->publish($published, $admin);
        $this->actingAs($admin)->delete(route('workflow-versions.destroy', $published))->assertForbidden();
        $this->assertDatabaseHas('workflow_versions', ['id' => $published->id, 'status' => 'PUBLISHED']);
    }

    public function test_published_version_screen_is_read_only(): void
    {
        $this->withoutVite();
        $admin = User::factory()->admin()->create();
        [$version] = $this->validDraft($admin);
        app(WorkflowConfigurationService::class)->publish($version, $admin);

        $this->actingAs($admin)->get(route('workflow-versions.edit', $version))
            ->assertOk()
            ->assertSee('immutable')
            ->assertDontSee('Add rule group')
            ->assertDontSee('Validate and publish');
    }

    /** @return array{WorkflowVersion, WorkflowRuleGroup, WorkflowRule, WorkflowStep} */
    private function validDraft(User $admin, ?WorkflowTemplate $template = null, int $versionNumber = 1): array
    {
        $template ??= WorkflowTemplate::factory()->create();
        $version = WorkflowVersion::factory()->create([
            'workflow_template_id' => $template,
            'version' => $versionNumber,
            'created_by' => $admin,
            'status' => WorkflowVersionStatus::Draft,
        ]);
        $group = WorkflowRuleGroup::factory()->create(['workflow_version_id' => $version, 'priority' => 10]);
        $department = Department::factory()->create();
        $rule = WorkflowRule::factory()->create([
            'workflow_rule_group_id' => $group,
            'field' => WorkflowRuleField::Department,
            'operator' => WorkflowRuleOperator::Equal,
            'value' => $department->id,
        ]);
        $step = WorkflowStep::factory()->create(['workflow_rule_group_id' => $group, 'step_order' => 1]);

        return [$version, $group, $rule, $step];
    }
}
