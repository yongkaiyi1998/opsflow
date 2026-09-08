<?php

namespace App\Services;

use App\ApprovalMode;
use App\ApproverType;
use App\Models\Department;
use App\Models\SpendCategory;
use App\Models\User;
use App\Models\WorkflowRule;
use App\Models\WorkflowRuleGroup;
use App\Models\WorkflowStep;
use App\Models\WorkflowTemplate;
use App\Models\WorkflowVersion;
use App\Support\Money;
use App\UserRole;
use App\UserStatus;
use App\WorkflowRuleField;
use App\WorkflowRuleOperator;
use App\WorkflowVersionStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use OverflowException;

class WorkflowConfigurationService
{
    public function __construct(private readonly AuditService $auditService) {}

    /** @param array<string, mixed> $attributes */
    public function createTemplate(array $attributes, User $user): WorkflowTemplate
    {
        return DB::transaction(function () use ($attributes, $user): WorkflowTemplate {
            $template = WorkflowTemplate::create($attributes);
            $template->versions()->forceCreate([
                'version' => 1,
                'status' => WorkflowVersionStatus::Draft,
                'created_by' => $user->id,
            ]);

            return $template;
        });
    }

    public function createDraft(WorkflowTemplate $template, User $user): WorkflowVersion
    {
        return DB::transaction(function () use ($template, $user): WorkflowVersion {
            $lockedTemplate = WorkflowTemplate::whereKey($template->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($user)->authorize('update', $lockedTemplate);
            $nextVersion = ((int) $lockedTemplate->versions()->max('version')) + 1;

            return $lockedTemplate->versions()->forceCreate([
                'version' => $nextVersion,
                'status' => WorkflowVersionStatus::Draft,
                'created_by' => $user->id,
            ]);
        }, 5);
    }

    /** @param array{name: string, priority: int, is_default: bool} $attributes */
    public function saveRuleGroup(WorkflowVersion $version, array $attributes, User $user, ?WorkflowRuleGroup $ruleGroup = null): WorkflowRuleGroup
    {
        return DB::transaction(function () use ($version, $attributes, $user, $ruleGroup): WorkflowRuleGroup {
            $lockedVersion = $this->lockEditableVersion($version, $user);

            if ($ruleGroup !== null && $ruleGroup->workflow_version_id !== $lockedVersion->id) {
                throw ValidationException::withMessages(['rule_group' => 'The rule group does not belong to this workflow version.']);
            }

            if ($attributes['is_default'] && $lockedVersion->ruleGroups()
                ->where('is_default', true)
                ->when($ruleGroup, fn (Builder $query): Builder => $query->whereKeyNot($ruleGroup->id))
                ->exists()) {
                throw ValidationException::withMessages(['is_default' => 'A workflow version may have only one default rule group.']);
            }

            if ($ruleGroup === null) {
                return $lockedVersion->ruleGroups()->create($attributes);
            }

            $lockedRuleGroup = WorkflowRuleGroup::whereKey($ruleGroup->id)->lockForUpdate()->firstOrFail();
            $lockedRuleGroup->update($attributes);

            return $lockedRuleGroup;
        }, 5);
    }

    /** @param array{field: string, operator: string, value: mixed} $attributes */
    public function saveRule(WorkflowRuleGroup $ruleGroup, array $attributes, User $user, ?WorkflowRule $rule = null): WorkflowRule
    {
        return DB::transaction(function () use ($ruleGroup, $attributes, $user, $rule): WorkflowRule {
            $lockedRuleGroup = $this->lockRuleGroup($ruleGroup, $user);

            if ($lockedRuleGroup->is_default) {
                throw ValidationException::withMessages(['rule' => 'Default rule groups cannot contain matching rules.']);
            }

            if ($rule !== null && $rule->workflow_rule_group_id !== $lockedRuleGroup->id) {
                throw ValidationException::withMessages(['rule' => 'The rule does not belong to this rule group.']);
            }

            if ($rule === null) {
                return $lockedRuleGroup->rules()->create($attributes);
            }

            $lockedRule = WorkflowRule::whereKey($rule->id)->lockForUpdate()->firstOrFail();
            $lockedRule->update($attributes);

            return $lockedRule;
        }, 5);
    }

    /** @param array{step_order: int, name: string, approver_type: string, approver_value: ?string, approval_mode: string, minimum_approvals: null, sla_hours: ?int} $attributes */
    public function saveStep(WorkflowRuleGroup $ruleGroup, array $attributes, User $user, ?WorkflowStep $step = null): WorkflowStep
    {
        return DB::transaction(function () use ($ruleGroup, $attributes, $user, $step): WorkflowStep {
            $lockedRuleGroup = $this->lockRuleGroup($ruleGroup, $user);

            if ($step !== null && $step->workflow_rule_group_id !== $lockedRuleGroup->id) {
                throw ValidationException::withMessages(['step' => 'The step does not belong to this rule group.']);
            }

            $duplicateOrder = $lockedRuleGroup->steps()->where('step_order', $attributes['step_order'])
                ->when($step, fn (Builder $query): Builder => $query->whereKeyNot($step->id))->exists();

            if ($duplicateOrder) {
                throw ValidationException::withMessages(['step_order' => 'Step order must be unique within the rule group.']);
            }

            if ($step === null) {
                return $lockedRuleGroup->steps()->create($attributes);
            }

            $lockedStep = WorkflowStep::whereKey($step->id)->lockForUpdate()->firstOrFail();
            $lockedStep->update($attributes);

            return $lockedStep;
        }, 5);
    }

    public function deleteRuleGroup(WorkflowRuleGroup $ruleGroup, User $user): void
    {
        DB::transaction(function () use ($ruleGroup, $user): void {
            $lockedRuleGroup = $this->lockRuleGroup($ruleGroup, $user);
            $lockedRuleGroup->rules()->delete();
            $lockedRuleGroup->steps()->delete();
            $lockedRuleGroup->delete();
        }, 5);
    }

    public function deleteRule(WorkflowRule $rule, User $user): void
    {
        DB::transaction(function () use ($rule, $user): void {
            $lockedRuleGroup = $this->lockRuleGroup($rule->ruleGroup, $user);
            $lockedRuleGroup->rules()->whereKey($rule->id)->lockForUpdate()->firstOrFail()->delete();
        }, 5);
    }

    public function deleteStep(WorkflowStep $step, User $user): void
    {
        DB::transaction(function () use ($step, $user): void {
            $lockedRuleGroup = $this->lockRuleGroup($step->ruleGroup, $user);
            $lockedRuleGroup->steps()->whereKey($step->id)->lockForUpdate()->firstOrFail()->delete();
        }, 5);
    }

    public function deleteDraft(WorkflowVersion $version, User $user): void
    {
        DB::transaction(function () use ($version, $user): void {
            $lockedTemplate = WorkflowTemplate::whereKey($version->workflow_template_id)->lockForUpdate()->firstOrFail();
            $lockedVersion = WorkflowVersion::whereKey($version->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($user)->authorize('delete', $lockedVersion);

            $groupIds = $lockedVersion->ruleGroups()->pluck('id');
            WorkflowRule::whereIn('workflow_rule_group_id', $groupIds)->delete();
            WorkflowStep::whereIn('workflow_rule_group_id', $groupIds)->delete();
            WorkflowRuleGroup::whereIn('id', $groupIds)->delete();
            $lockedVersion->delete();
        }, 5);
    }

    public function publish(WorkflowVersion $version, User $user, ?Request $request = null): WorkflowVersion
    {
        return DB::transaction(function () use ($version, $user, $request): WorkflowVersion {
            WorkflowTemplate::whereKey($version->workflow_template_id)->lockForUpdate()->firstOrFail();
            $lockedVersion = WorkflowVersion::whereKey($version->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($user)->authorize('publish', $lockedVersion);
            $lockedVersion->load(['ruleGroups.rules', 'ruleGroups.steps']);
            $this->validateForPublication($lockedVersion);
            $publishedAt = now();

            WorkflowVersion::where('workflow_template_id', $lockedVersion->workflow_template_id)
                ->where('status', WorkflowVersionStatus::Published->value)
                ->whereKeyNot($lockedVersion->id)
                ->update(['status' => WorkflowVersionStatus::Archived->value, 'effective_until' => $publishedAt]);

            $lockedVersion->forceFill([
                'status' => WorkflowVersionStatus::Published,
                'published_by' => $user->id,
                'published_at' => $publishedAt,
                'effective_from' => $publishedAt,
                'effective_until' => null,
            ])->save();
            $this->auditService->logWorkflowPublished($lockedVersion, $user, $request);

            return $lockedVersion->refresh();
        }, 5);
    }

    public function clonePublished(WorkflowVersion $version, User $user): WorkflowVersion
    {
        return DB::transaction(function () use ($version, $user): WorkflowVersion {
            $lockedTemplate = WorkflowTemplate::whereKey($version->workflow_template_id)->lockForUpdate()->firstOrFail();
            $source = WorkflowVersion::whereKey($version->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($user)->authorize('clone', $source);
            $source->load(['ruleGroups.rules', 'ruleGroups.steps']);

            $clone = $lockedTemplate->versions()->forceCreate([
                'version' => ((int) $lockedTemplate->versions()->max('version')) + 1,
                'status' => WorkflowVersionStatus::Draft,
                'created_by' => $user->id,
            ]);

            foreach ($source->ruleGroups as $sourceGroup) {
                $group = $clone->ruleGroups()->create($sourceGroup->only(['name', 'priority', 'is_default']));
                $group->rules()->createMany($sourceGroup->rules->map->only(['field', 'operator', 'value'])->all());
                $group->steps()->createMany($sourceGroup->steps->map->only([
                    'step_order', 'name', 'approver_type', 'approver_value', 'approval_mode', 'minimum_approvals', 'sla_hours',
                ])->all());
            }

            return $clone->load(['ruleGroups.rules', 'ruleGroups.steps']);
        }, 5);
    }

    private function lockEditableVersion(WorkflowVersion $version, User $user): WorkflowVersion
    {
        $lockedVersion = WorkflowVersion::whereKey($version->id)->lockForUpdate()->firstOrFail();
        Gate::forUser($user)->authorize('update', $lockedVersion);

        return $lockedVersion;
    }

    private function lockRuleGroup(WorkflowRuleGroup $ruleGroup, User $user): WorkflowRuleGroup
    {
        $lockedVersion = $this->lockEditableVersion($ruleGroup->version, $user);

        return $lockedVersion->ruleGroups()->whereKey($ruleGroup->id)->lockForUpdate()->firstOrFail();
    }

    private function validateForPublication(WorkflowVersion $version): void
    {
        $errors = [];

        if ($version->ruleGroups->isEmpty()) {
            $errors['workflow'][] = 'Add at least one rule group before publication.';
        }

        if ($version->ruleGroups->where('is_default', true)->count() > 1) {
            $errors['workflow'][] = 'Only one default rule group is allowed.';
        }

        foreach ($version->ruleGroups as $group) {
            if (! $group->is_default && $group->rules->isEmpty()) {
                $errors['workflow'][] = "{$group->name} requires at least one rule.";
            }

            if ($group->is_default && $group->rules->isNotEmpty()) {
                $errors['workflow'][] = "{$group->name} is the default group and cannot contain matching rules.";
            }

            if ($group->steps->isEmpty()) {
                $errors['workflow'][] = "{$group->name} requires at least one approval step.";
            }

            if ($group->steps->isNotEmpty()
                && $group->steps->pluck('step_order')->all() !== range(1, $group->steps->count())) {
                $errors['workflow'][] = "{$group->name} step order must start at 1 and be sequential.";
            }

            foreach ($group->rules as $rule) {
                $this->validateRule($rule, $group->name, $errors);
            }

            foreach ($group->steps as $step) {
                $this->validateStep($step, $group->name, $errors);
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /** @param array<string, list<string>> $errors */
    private function validateRule(WorkflowRule $rule, string $groupName, array &$errors): void
    {
        $field = WorkflowRuleField::tryFrom((string) $rule->getRawOriginal('field'));
        $operator = WorkflowRuleOperator::tryFrom((string) $rule->getRawOriginal('operator'));

        if ($field === null || $operator === null || ! in_array($operator, $field->operators(), true)) {
            $errors['workflow'][] = "{$groupName} contains an unsupported rule field or operator.";

            return;
        }

        $value = $rule->value;

        try {
            if ($field === WorkflowRuleField::Amount) {
                $money = Money::of(is_int($value) || is_string($value) ? $value : 'invalid');

                if ($money->compare(0) < 0) {
                    throw new InvalidArgumentException;
                }
            } else {
                $values = $operator === WorkflowRuleOperator::In ? $value : [$value];

                if (! is_array($values) || $values === [] || collect($values)->contains(fn (mixed $id): bool => ! is_int($id))) {
                    throw new InvalidArgumentException;
                }

                $model = $field === WorkflowRuleField::Department ? Department::class : SpendCategory::class;

                if ($model::whereKey($values)->count() !== count(array_unique($values))) {
                    throw new InvalidArgumentException;
                }
            }
        } catch (InvalidArgumentException|OverflowException) {
            $errors['workflow'][] = "{$groupName} contains an invalid {$field->label()} rule value.";
        }
    }

    /** @param array<string, list<string>> $errors */
    private function validateStep(WorkflowStep $step, string $groupName, array &$errors): void
    {
        $approverType = ApproverType::tryFrom((string) $step->getRawOriginal('approver_type'));
        $approvalMode = ApprovalMode::tryFrom((string) $step->getRawOriginal('approval_mode'));
        $value = $step->approver_value;
        $valid = $approverType !== null && $approvalMode === ApprovalMode::Any;

        if (in_array($approverType, [ApproverType::RequesterManager, ApproverType::DepartmentManager], true)) {
            $valid = $valid && $value === null;
        } elseif ($approverType === ApproverType::Role) {
            $valid = $valid && is_string($value) && UserRole::tryFrom($value) !== null;
        } elseif ($approverType === ApproverType::SpecificUser) {
            $valid = $valid && ctype_digit((string) $value)
                && User::whereKey((int) $value)->where('status', UserStatus::Active->value)->exists();
        }

        if (! $valid) {
            $errors['workflow'][] = "{$groupName} contains an invalid approval step.";
        }
    }
}
