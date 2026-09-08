<?php

namespace Tests\Feature;

use App\Exceptions\InvalidWorkflowConfigurationException;
use App\Exceptions\WorkflowResolutionException;
use App\MasterDataStatus;
use App\Models\WorkflowRuleGroup;
use App\Models\WorkflowTemplate;
use App\Models\WorkflowVersion;
use App\Services\WorkflowResolver;
use App\WorkflowContext;
use App\WorkflowModuleType;
use App\WorkflowRuleField;
use App\WorkflowRuleOperator;
use App\WorkflowVersionStatus;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WorkflowResolverTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_first_matching_non_default_group_wins_by_priority_then_id(): void
    {
        $version = $this->draftVersion();
        $laterPriority = $this->addGroup($version, 'General high value', 20, [
            [WorkflowRuleField::Amount, WorkflowRuleOperator::GreaterThan, '10000.00'],
        ]);
        $firstPriority = $this->addGroup($version, 'Department high value', 10, [
            [WorkflowRuleField::Amount, WorkflowRuleOperator::GreaterThan, '10000.00'],
            [WorkflowRuleField::Department, WorkflowRuleOperator::Equal, 12],
        ]);
        $samePriorityLaterId = $this->addGroup($version, 'Same priority later id', 10, [
            [WorkflowRuleField::Amount, WorkflowRuleOperator::GreaterThan, '10000.00'],
        ]);
        $this->publish($version);

        $resolution = app(WorkflowResolver::class)->resolve($this->context(amount: '10000.01'));

        $this->assertSame($firstPriority->id, $resolution->ruleGroup->id);
        $this->assertNotSame($laterPriority->id, $resolution->ruleGroup->id);
        $this->assertNotSame($samePriorityLaterId->id, $resolution->ruleGroup->id);
    }

    public function test_rules_in_one_group_use_and_semantics(): void
    {
        $version = $this->draftVersion();
        $this->addGroup($version, 'Wrong department', 10, [
            [WorkflowRuleField::Amount, WorkflowRuleOperator::GreaterThan, '100.00'],
            [WorkflowRuleField::Department, WorkflowRuleOperator::Equal, 99],
        ]);
        $matching = $this->addGroup($version, 'Matching route', 20, [
            [WorkflowRuleField::Amount, WorkflowRuleOperator::GreaterThan, '100.00'],
        ]);
        $this->publish($version);

        $resolution = app(WorkflowResolver::class)->resolve($this->context(amount: '101.00'));

        $this->assertSame($matching->id, $resolution->ruleGroup->id);
    }

    public function test_default_group_is_used_only_as_fallback(): void
    {
        $version = $this->draftVersion();
        $default = $this->addGroup($version, 'Default', 1, [], true);
        $specific = $this->addGroup($version, 'Specific', 50, [
            [WorkflowRuleField::Category, WorkflowRuleOperator::In, [34, 35]],
        ]);
        $this->publish($version);

        $resolver = app(WorkflowResolver::class);

        $this->assertSame($specific->id, $resolver->resolve($this->context(categoryId: 34))->ruleGroup->id);
        $this->assertSame($default->id, $resolver->resolve($this->context(categoryId: 99))->ruleGroup->id);
    }

    public function test_null_context_values_do_not_match_and_can_fall_back_to_default(): void
    {
        $version = $this->draftVersion();
        $this->addGroup($version, 'Department route', 10, [
            [WorkflowRuleField::Department, WorkflowRuleOperator::NotEqual, 99],
        ]);
        $default = $this->addGroup($version, 'Default', 20, [], true);
        $this->publish($version);

        $resolution = app(WorkflowResolver::class)->resolve($this->context(departmentId: null));

        $this->assertSame($default->id, $resolution->ruleGroup->id);
    }

    public function test_resolution_returns_the_published_version_context_and_ordered_steps(): void
    {
        $version = $this->draftVersion();
        $group = $this->addGroup($version, 'Default', 10, [], true, [1, 2]);
        $this->publish($version);
        $context = $this->context(amount: '250.50');

        $resolution = app(WorkflowResolver::class)->resolve($context);

        $this->assertSame($version->id, $resolution->version->id);
        $this->assertSame($group->id, $resolution->ruleGroup->id);
        $this->assertSame($context, $resolution->context);
        $this->assertSame([1, 2], $resolution->steps()->pluck('step_order')->all());
    }

    public function test_resolution_is_repeatable_for_the_same_context_and_configuration(): void
    {
        $version = $this->draftVersion();
        $group = $this->addGroup($version, 'Matching', 10, [
            [WorkflowRuleField::Amount, WorkflowRuleOperator::GreaterThanOrEqual, '0.30'],
        ]);
        $this->publish($version);
        $context = $this->context(amount: '0.30');
        $resolver = app(WorkflowResolver::class);

        $results = collect(range(1, 5))->map(fn () => $resolver->resolve($context));

        $this->assertSame([$group->id], $results->pluck('ruleGroup.id')->unique()->values()->all());
        $this->assertSame([$version->id], $results->pluck('version.id')->unique()->values()->all());
    }

    public function test_archived_versions_are_ignored_in_favor_of_the_current_published_version(): void
    {
        $template = WorkflowTemplate::factory()->create();
        $archived = $this->draftVersion($template, 1);
        $this->addGroup($archived, 'Archived route', 10, [], true);
        $this->setVersionStatus($archived, WorkflowVersionStatus::Archived);
        $published = $this->draftVersion($template, 2);
        $publishedGroup = $this->addGroup($published, 'Published route', 10, [], true);
        $this->publish($published);

        $resolution = app(WorkflowResolver::class)->resolve($this->context());

        $this->assertSame($published->id, $resolution->version->id);
        $this->assertSame($publishedGroup->id, $resolution->ruleGroup->id);
    }

    public function test_no_matching_or_default_route_fails_with_a_safe_message(): void
    {
        $version = $this->draftVersion();
        $this->addGroup($version, 'Large amount', 10, [
            [WorkflowRuleField::Amount, WorkflowRuleOperator::GreaterThan, '10000.00'],
        ]);
        $this->publish($version);

        $this->expectException(WorkflowResolutionException::class);
        $this->expectExceptionMessage('No approval workflow is configured for this request. Please contact an administrator.');

        app(WorkflowResolver::class)->resolve($this->context(amount: '100.00'));
    }

    public function test_missing_active_template_or_published_version_fails_safely(): void
    {
        WorkflowTemplate::factory()->create(['status' => MasterDataStatus::Inactive]);

        try {
            app(WorkflowResolver::class)->resolve($this->context());
            $this->fail('An inactive template must not resolve.');
        } catch (WorkflowResolutionException $exception) {
            $this->assertSame('No approval workflow is configured for this request. Please contact an administrator.', $exception->getMessage());
        }

        WorkflowTemplate::query()->delete();
        $this->draftVersion();

        $this->expectException(WorkflowResolutionException::class);
        app(WorkflowResolver::class)->resolve($this->context());
    }

    public function test_invalid_published_configuration_fails_closed(): void
    {
        $version = $this->draftVersion();
        $group = $this->addGroup($version, 'Corrupt route', 10, [
            [WorkflowRuleField::Amount, WorkflowRuleOperator::GreaterThan, '100.00'],
        ]);
        $this->publish($version);
        DB::table('workflow_rules')->where('workflow_rule_group_id', $group->id)->update(['operator' => 'IN']);

        $this->expectException(InvalidWorkflowConfigurationException::class);

        app(WorkflowResolver::class)->resolve($this->context(amount: '101.00'));
    }

    private function context(
        string $amount = '1000.00',
        ?int $departmentId = 12,
        ?int $categoryId = 34,
    ): WorkflowContext {
        return WorkflowContext::fromValues(
            WorkflowModuleType::PurchaseRequest,
            1,
            $departmentId,
            $categoryId,
            $amount,
            'MYR',
        );
    }

    private function draftVersion(?WorkflowTemplate $template = null, int $version = 1): WorkflowVersion
    {
        return WorkflowVersion::factory()->create([
            'workflow_template_id' => $template ?? WorkflowTemplate::factory(),
            'version' => $version,
        ]);
    }

    /**
     * @param  list<array{WorkflowRuleField, WorkflowRuleOperator, mixed}>  $rules
     * @param  list<int>  $stepOrders
     */
    private function addGroup(
        WorkflowVersion $version,
        string $name,
        int $priority,
        array $rules,
        bool $isDefault = false,
        array $stepOrders = [1],
    ): WorkflowRuleGroup {
        $group = $version->ruleGroups()->create([
            'name' => $name,
            'priority' => $priority,
            'is_default' => $isDefault,
        ]);

        foreach ($rules as [$field, $operator, $value]) {
            $group->rules()->create([
                'field' => $field,
                'operator' => $operator,
                'value' => $value,
            ]);
        }

        foreach ($stepOrders as $stepOrder) {
            $group->steps()->create([
                'step_order' => $stepOrder,
                'name' => "Approval {$stepOrder}",
                'approver_type' => 'REQUESTER_MANAGER',
                'approver_value' => null,
                'approval_mode' => 'ANY',
                'minimum_approvals' => null,
                'sla_hours' => null,
            ]);
        }

        return $group;
    }

    private function publish(WorkflowVersion $version): void
    {
        $this->setVersionStatus($version, WorkflowVersionStatus::Published);
    }

    private function setVersionStatus(WorkflowVersion $version, WorkflowVersionStatus $status): void
    {
        DB::table('workflow_versions')->where('id', $version->id)->update([
            'status' => $status->value,
        ]);
    }
}
