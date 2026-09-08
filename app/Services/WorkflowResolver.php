<?php

namespace App\Services;

use App\Exceptions\InvalidWorkflowConfigurationException;
use App\Exceptions\WorkflowResolutionException;
use App\MasterDataStatus;
use App\Models\WorkflowRuleGroup;
use App\Models\WorkflowTemplate;
use App\Models\WorkflowVersion;
use App\WorkflowContext;
use App\WorkflowResolution;
use App\WorkflowVersionStatus;

class WorkflowResolver
{
    public function __construct(private readonly RuleEvaluator $ruleEvaluator) {}

    public function resolve(WorkflowContext $context): WorkflowResolution
    {
        $template = WorkflowTemplate::query()
            ->where('module_type', $context->moduleType->value)
            ->where('status', MasterDataStatus::Active->value)
            ->first();

        if ($template === null) {
            throw WorkflowResolutionException::noRoute();
        }

        $publishedVersions = $template->versions()
            ->where('status', WorkflowVersionStatus::Published->value)
            ->with(['ruleGroups.rules', 'ruleGroups.steps'])
            ->limit(2)
            ->get();

        if ($publishedVersions->isEmpty()) {
            throw WorkflowResolutionException::noRoute();
        }

        if ($publishedVersions->count() > 1) {
            throw new InvalidWorkflowConfigurationException('The workflow template must have exactly one current published version.');
        }

        $version = $publishedVersions->first();
        $defaultGroups = $version->ruleGroups->where('is_default', true);

        if ($defaultGroups->count() > 1) {
            throw new InvalidWorkflowConfigurationException('The published workflow contains more than one default rule group.');
        }

        foreach ($version->ruleGroups->where('is_default', false) as $group) {
            if ($this->ruleEvaluator->matchesGroup($group, $context)) {
                return $this->result($version, $group, $context);
            }
        }

        $default = $defaultGroups->first();

        if ($default === null) {
            throw WorkflowResolutionException::noRoute();
        }

        if ($default->rules->isNotEmpty()) {
            throw new InvalidWorkflowConfigurationException('The default rule group cannot contain matching rules.');
        }

        return $this->result($version, $default, $context);
    }

    private function result(WorkflowVersion $version, WorkflowRuleGroup $group, WorkflowContext $context): WorkflowResolution
    {
        $orders = $group->steps->pluck('step_order')->all();

        if ($group->steps->isEmpty() || $orders !== range(1, count($orders))) {
            throw new InvalidWorkflowConfigurationException('The selected rule group does not contain valid ordered approval steps.');
        }

        return new WorkflowResolution($version, $group, $context);
    }
}
