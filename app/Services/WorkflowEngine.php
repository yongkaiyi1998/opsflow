<?php

namespace App\Services;

use App\ApprovalActionType;
use App\ApprovalAssignmentStatus;
use App\ApprovalInstanceStatus;
use App\ApprovalMode;
use App\ApprovalStepStatus;
use App\ApproverType;
use App\Exceptions\ApprovalRuntimeException;
use App\Models\ApprovalAssignment;
use App\Models\ApprovalInstance;
use App\Models\ApprovalStepInstance;
use App\Models\User;
use App\Models\WorkflowRuleGroup;
use App\Models\WorkflowStep;
use App\Models\WorkflowVersion;
use App\UserRole;
use App\UserStatus;
use App\WorkflowResolution;
use App\WorkflowVersionStatus;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class WorkflowEngine
{
    public function __construct(
        private readonly ApproverResolver $approverResolver,
        private readonly WorkflowNotifier $workflowNotifier,
    ) {}

    public function validateStart(Model $approvable, WorkflowResolution $resolution): void
    {
        if (! $approvable->exists || $approvable->getKey() === null) {
            throw ApprovalRuntimeException::invalidResolution();
        }

        [, $group] = $this->authoritativeRoute($resolution);
        $requesterExists = User::query()
            ->whereKey($resolution->context->requesterId)
            ->where('status', UserStatus::Active->value)
            ->exists();

        if (! $requesterExists) {
            throw ApprovalRuntimeException::invalidResolution();
        }

        $steps = $group->steps;
        $this->validateSteps($steps->all());
        $this->approverResolver->resolve($steps->first(), $resolution->context);
    }

    public function start(Model $approvable, WorkflowResolution $resolution): ApprovalInstance
    {
        return $this->startRuntime(
            $approvable,
            $resolution,
            ApprovalActionType::Submitted,
        );
    }

    public function startReplacement(
        Model $approvable,
        WorkflowResolution $resolution,
        User $actor,
        ApprovalInstance $previousInstance,
        ?string $comment = null,
    ): ApprovalInstance {
        return $this->startRuntime(
            $approvable,
            $resolution,
            ApprovalActionType::Resubmitted,
            $actor,
            $previousInstance,
            $comment,
        );
    }

    private function startRuntime(
        Model $approvable,
        WorkflowResolution $resolution,
        ApprovalActionType $startupAction,
        ?User $actor = null,
        ?ApprovalInstance $previousInstance = null,
        ?string $comment = null,
    ): ApprovalInstance {
        if (! $approvable->exists || $approvable->getKey() === null) {
            throw ApprovalRuntimeException::invalidResolution();
        }

        return DB::transaction(function () use (
            $approvable,
            $resolution,
            $startupAction,
            $actor,
            $previousInstance,
            $comment,
        ): ApprovalInstance {
            $lockedApprovable = $approvable->newQuery()
                ->whereKey($approvable->getKey())
                ->lockForUpdate()
                ->first();

            if ($lockedApprovable === null) {
                throw ApprovalRuntimeException::invalidResolution();
            }

            $this->ensureNoActiveRuntime($lockedApprovable);
            [$version, $group] = $this->authoritativeRoute($resolution);
            $requester = User::query()
                ->whereKey($resolution->context->requesterId)
                ->where('status', UserStatus::Active->value)
                ->first();

            if ($requester === null) {
                throw ApprovalRuntimeException::invalidResolution();
            }

            $actionActor = $requester;

            if ($startupAction === ApprovalActionType::Resubmitted) {
                $actionActor = User::query()
                    ->whereKey($actor?->id)
                    ->where('status', UserStatus::Active->value)
                    ->lockForUpdate()
                    ->first();

                if ($actionActor === null || $previousInstance === null) {
                    throw ApprovalRuntimeException::invalidResolution();
                }
            }

            $steps = $group->steps;
            $this->validateSteps($steps->all());
            $startedAt = now();
            $instance = ApprovalInstance::create([
                'approvable_type' => $lockedApprovable->getMorphClass(),
                'approvable_id' => $lockedApprovable->getKey(),
                'workflow_version_id' => $version->id,
                'workflow_rule_group_id' => $group->id,
                'status' => ApprovalInstanceStatus::InProgress,
                'current_step_order' => 1,
                'workflow_context' => $resolution->context->snapshot(),
                'started_at' => $startedAt,
            ]);
            $activeAssignments = new Collection;

            foreach ($steps as $configuredStep) {
                $runtimeStep = $instance->steps()->create([
                    'workflow_step_id' => $configuredStep->id,
                    'step_order' => $configuredStep->step_order,
                    'name' => $configuredStep->name,
                    'approver_type' => $configuredStep->getRawOriginal('approver_type'),
                    'approver_value' => $configuredStep->approver_value,
                    'approval_mode' => ApprovalMode::Any,
                    'required_approvals' => 1,
                    'status' => $configuredStep->step_order === 1 ? ApprovalStepStatus::Active : ApprovalStepStatus::Waiting,
                    'started_at' => $configuredStep->step_order === 1 ? $startedAt : null,
                ]);

                if ($configuredStep->step_order === 1) {
                    $activeAssignments = $this->assignApprovers($runtimeStep, $resolution);
                }
            }

            $instance->actions()->create([
                'actor_id' => $actionActor->id,
                'action' => $startupAction,
                'comment' => $comment,
                'metadata' => $startupAction === ApprovalActionType::Resubmitted ? [
                    'routing_changed' => true,
                    'previous_approval_instance_id' => $previousInstance->id,
                    'new_approval_instance_id' => $instance->id,
                ] : null,
                'created_at' => $startedAt,
            ]);
            $this->workflowNotifier->assignmentsCreated(
                $activeAssignments,
                $lockedApprovable,
                $startupAction === ApprovalActionType::Resubmitted,
            );

            return $instance->load(['workflowVersion', 'workflowRuleGroup', 'steps.assignments', 'actions']);
        }, 5);
    }

    private function ensureNoActiveRuntime(Model $approvable): void
    {
        $exists = ApprovalInstance::query()
            ->where('approvable_type', $approvable->getMorphClass())
            ->where('approvable_id', $approvable->getKey())
            ->whereIn('status', [ApprovalInstanceStatus::InProgress->value, ApprovalInstanceStatus::Blocked->value])
            ->exists();

        if ($exists) {
            throw ApprovalRuntimeException::duplicateRuntime();
        }
    }

    /** @return array{WorkflowVersion, WorkflowRuleGroup} */
    private function authoritativeRoute(WorkflowResolution $resolution): array
    {
        $version = WorkflowVersion::query()
            ->whereKey($resolution->version->id)
            ->where('status', WorkflowVersionStatus::Published->value)
            ->first();
        $group = WorkflowRuleGroup::query()
            ->whereKey($resolution->ruleGroup->id)
            ->where('workflow_version_id', $resolution->version->id)
            ->with('steps')
            ->first();

        if ($version === null || $group === null) {
            throw ApprovalRuntimeException::invalidResolution();
        }

        return [$version, $group];
    }

    /** @param list<WorkflowStep> $steps */
    private function validateSteps(array $steps): void
    {
        $stepOrders = array_map(fn (WorkflowStep $step): int => (int) $step->step_order, $steps);

        if ($steps === [] || $stepOrders !== range(1, count($steps))) {
            throw ApprovalRuntimeException::invalidResolution();
        }

        foreach ($steps as $step) {
            $approverType = ApproverType::tryFrom((string) $step->getRawOriginal('approver_type'));
            $approvalMode = ApprovalMode::tryFrom((string) $step->getRawOriginal('approval_mode'));
            $value = $step->approver_value;

            if ($approvalMode !== ApprovalMode::Any || $approverType === null) {
                throw ApprovalRuntimeException::invalidResolution();
            }

            $validValue = match ($approverType) {
                ApproverType::RequesterManager, ApproverType::DepartmentManager => $value === null,
                ApproverType::Role => is_string($value) && UserRole::tryFrom($value) !== null,
                ApproverType::SpecificUser => is_string($value) && ctype_digit($value) && (int) $value > 0,
            };

            if (! $validValue) {
                throw ApprovalRuntimeException::invalidResolution();
            }
        }
    }

    /** @return Collection<int, ApprovalAssignment> */
    private function assignApprovers(ApprovalStepInstance $step, WorkflowResolution $resolution): Collection
    {
        $assignedAt = now();
        $assignments = new Collection;

        foreach ($this->approverResolver->resolve($step, $resolution->context) as $approver) {
            $assignments->push($step->assignments()->create([
                'approver_id' => $approver->id,
                'status' => ApprovalAssignmentStatus::Pending,
                'assigned_at' => $assignedAt,
            ]));
        }

        return $assignments;
    }
}
