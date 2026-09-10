<?php

namespace App\Services;

use App\ApprovalActionType;
use App\ApprovalAssignmentStatus;
use App\ApprovalInstanceStatus;
use App\ApprovalMode;
use App\ApprovalStepStatus;
use App\Exceptions\ApprovalRuntimeException;
use App\Models\ApprovalAssignment;
use App\Models\ApprovalInstance;
use App\Models\ApprovalStepInstance;
use App\Models\ExpenseClaim;
use App\Models\PurchaseRequest;
use App\Models\SupplierInvoice;
use App\Models\User;
use App\UserStatus;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ApprovalService
{
    public function __construct(private readonly ApproverResolver $approverResolver) {}

    public function approve(ApprovalAssignment $assignment, User $actor, ?string $comment = null): ApprovalInstance
    {
        return $this->perform($assignment, $actor, ApprovalActionType::Approved, $comment);
    }

    public function reject(ApprovalAssignment $assignment, User $actor, string $comment): ApprovalInstance
    {
        return $this->perform($assignment, $actor, ApprovalActionType::Rejected, $comment);
    }

    public function requestChanges(ApprovalAssignment $assignment, User $actor, string $comment): ApprovalInstance
    {
        return $this->perform($assignment, $actor, ApprovalActionType::ChangesRequested, $comment);
    }

    private function perform(
        ApprovalAssignment $assignment,
        User $actor,
        ApprovalActionType $action,
        ?string $comment,
    ): ApprovalInstance {
        $ability = match ($action) {
            ApprovalActionType::Approved => 'approve',
            ApprovalActionType::Rejected => 'reject',
            ApprovalActionType::ChangesRequested => 'requestChanges',
            default => throw new \LogicException('Unsupported approval action.'),
        };
        Gate::forUser($actor)->authorize($ability, $assignment);
        $comment = $this->normalizeComment($action, $comment);
        [$businessLocator, $instanceId, $stepId] = $this->locate($assignment);

        try {
            return DB::transaction(function () use (
                $assignment,
                $actor,
                $action,
                $ability,
                $comment,
                $businessLocator,
                $instanceId,
                $stepId,
            ): ApprovalInstance {
                $business = $businessLocator->newQuery()
                    ->whereKey($businessLocator->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();
                $instance = ApprovalInstance::query()->whereKey($instanceId)->lockForUpdate()->firstOrFail();
                $step = ApprovalStepInstance::query()->whereKey($stepId)->lockForUpdate()->firstOrFail();
                [$nextStep, $futureSteps] = $this->lockRequiredSteps($instance, $step, $action);
                $stepIds = collect([$step->id])
                    ->merge($futureSteps->pluck('id'))
                    ->when($nextStep !== null, fn ($ids) => $ids->push($nextStep->id))
                    ->unique();
                $assignments = ApprovalAssignment::query()
                    ->whereIn('approval_step_instance_id', $stepIds)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();
                $lockedAssignment = $assignments->firstWhere('id', $assignment->id);

                if (! $lockedAssignment instanceof ApprovalAssignment) {
                    throw $this->staleAction();
                }

                $lockedActor = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
                $instance->setRelation('approvable', $business);
                $step->setRelation('approvalInstance', $instance);
                $lockedAssignment->setRelation('step', $step);
                Gate::forUser($lockedActor)->authorize($ability, $lockedAssignment);
                $this->validateAuthoritativeState($business, $instance, $step, $lockedAssignment, $lockedActor);
                $currentAssignments = $assignments->where('approval_step_instance_id', $step->id);
                $actedAt = now();

                match ($action) {
                    ApprovalActionType::Approved => $this->applyApproval(
                        $business,
                        $instance,
                        $step,
                        $lockedAssignment,
                        $currentAssignments,
                        $nextStep,
                        $actedAt,
                    ),
                    ApprovalActionType::Rejected => $this->applyRejection(
                        $business,
                        $instance,
                        $step,
                        $lockedAssignment,
                        $currentAssignments,
                        $futureSteps,
                        $assignments,
                        $actedAt,
                    ),
                    ApprovalActionType::ChangesRequested => $this->applyChangesRequested(
                        $business,
                        $instance,
                        $lockedAssignment,
                        $currentAssignments,
                        $actedAt,
                    ),
                    default => throw new \LogicException('Unsupported approval action.'),
                };

                $instance->actions()->create([
                    'approval_step_instance_id' => $step->id,
                    'actor_id' => $lockedActor->id,
                    'action' => $action,
                    'comment' => $comment,
                    'created_at' => $actedAt,
                ]);

                return $instance->load(['approvable', 'steps.assignments.approver', 'actions.actor']);
            }, 5);
        } catch (ApprovalRuntimeException $exception) {
            throw ValidationException::withMessages(['workflow' => $exception->getMessage()]);
        }
    }

    /** @return array{Model, int, int} */
    private function locate(ApprovalAssignment $assignment): array
    {
        $locator = ApprovalAssignment::query()
            ->with('step.approvalInstance.approvable')
            ->whereKey($assignment->id)
            ->firstOrFail();
        $step = $locator->step;
        $instance = $step->approvalInstance;
        $business = $instance->approvable;

        if (! $business instanceof PurchaseRequest
            && ! $business instanceof SupplierInvoice
            && ! $business instanceof ExpenseClaim) {
            throw ValidationException::withMessages(['action' => 'This approval does not belong to a supported business record.']);
        }

        return [$business, $instance->id, $step->id];
    }

    /** @return array{?ApprovalStepInstance, Collection<int, ApprovalStepInstance>} */
    private function lockRequiredSteps(
        ApprovalInstance $instance,
        ApprovalStepInstance $step,
        ApprovalActionType $action,
    ): array {
        if ($action === ApprovalActionType::Approved) {
            $nextStep = ApprovalStepInstance::query()
                ->where('approval_instance_id', $instance->id)
                ->where('step_order', '>', $step->step_order)
                ->orderBy('step_order')
                ->lockForUpdate()
                ->first();

            return [$nextStep, new Collection];
        }

        if ($action === ApprovalActionType::Rejected) {
            $futureSteps = ApprovalStepInstance::query()
                ->where('approval_instance_id', $instance->id)
                ->where('step_order', '>', $step->step_order)
                ->orderBy('step_order')
                ->lockForUpdate()
                ->get();

            return [null, $futureSteps];
        }

        return [null, new Collection];
    }

    private function validateAuthoritativeState(
        Model $business,
        ApprovalInstance $instance,
        ApprovalStepInstance $step,
        ApprovalAssignment $assignment,
        User $actor,
    ): void {
        $valid = (string) $business->getRawOriginal('status') === 'IN_APPROVAL'
            && $instance->approvable_type === $business->getMorphClass()
            && (string) $instance->approvable_id === (string) $business->getKey()
            && $instance->status === ApprovalInstanceStatus::InProgress
            && $instance->current_step_order === $step->step_order
            && $step->approval_instance_id === $instance->id
            && $step->status === ApprovalStepStatus::Active
            && $step->approval_mode === ApprovalMode::Any
            && $assignment->approval_step_instance_id === $step->id
            && $assignment->status === ApprovalAssignmentStatus::Pending
            && $assignment->approver_id === $actor->id
            && $actor->status === UserStatus::Active
            && $instance->context()->requesterId !== $actor->id;

        if (! $valid) {
            throw $this->staleAction();
        }
    }

    /** @param Collection<int, ApprovalAssignment> $currentAssignments */
    private function applyApproval(
        Model $business,
        ApprovalInstance $instance,
        ApprovalStepInstance $step,
        ApprovalAssignment $assignment,
        Collection $currentAssignments,
        ?ApprovalStepInstance $nextStep,
        \DateTimeInterface $actedAt,
    ): void {
        $assignment->forceFill([
            'status' => ApprovalAssignmentStatus::Approved,
            'acted_at' => $actedAt,
        ])->save();
        $this->markOtherAssignments($currentAssignments, $assignment, ApprovalAssignmentStatus::Skipped);
        $step->forceFill([
            'status' => ApprovalStepStatus::Completed,
            'completed_at' => $actedAt,
        ])->save();

        if ($nextStep === null) {
            $instance->forceFill([
                'status' => ApprovalInstanceStatus::Approved,
                'current_step_order' => null,
                'completed_at' => $actedAt,
            ])->save();
            $this->updateBusinessStatus($business, 'APPROVED', 'approved_at', $actedAt);

            return;
        }

        if ($nextStep->status !== ApprovalStepStatus::Waiting
            || $nextStep->step_order !== $step->step_order + 1) {
            throw $this->staleAction();
        }

        $existingAssignments = $nextStep->assignments()->lockForUpdate()->get();

        if ($existingAssignments->isNotEmpty()) {
            throw $this->staleAction();
        }

        $nextStep->forceFill([
            'status' => ApprovalStepStatus::Active,
            'started_at' => $actedAt,
        ])->save();
        $this->createAssignments($nextStep, $instance, $actedAt);
        $instance->forceFill(['current_step_order' => $nextStep->step_order])->save();
    }

    /**
     * @param  Collection<int, ApprovalAssignment>  $currentAssignments
     * @param  Collection<int, ApprovalStepInstance>  $futureSteps
     * @param  Collection<int, ApprovalAssignment>  $allAssignments
     */
    private function applyRejection(
        Model $business,
        ApprovalInstance $instance,
        ApprovalStepInstance $step,
        ApprovalAssignment $assignment,
        Collection $currentAssignments,
        Collection $futureSteps,
        Collection $allAssignments,
        \DateTimeInterface $actedAt,
    ): void {
        $assignment->forceFill([
            'status' => ApprovalAssignmentStatus::Rejected,
            'acted_at' => $actedAt,
        ])->save();
        $this->markOtherAssignments($currentAssignments, $assignment, ApprovalAssignmentStatus::Skipped);
        $step->forceFill([
            'status' => ApprovalStepStatus::Rejected,
            'completed_at' => $actedAt,
        ])->save();

        foreach ($futureSteps as $futureStep) {
            $futureStep->forceFill([
                'status' => ApprovalStepStatus::Cancelled,
                'completed_at' => $actedAt,
            ])->save();
            foreach ($allAssignments
                ->where('approval_step_instance_id', $futureStep->id)
                ->where('status', ApprovalAssignmentStatus::Pending) as $futureAssignment) {
                $futureAssignment->forceFill(['status' => ApprovalAssignmentStatus::Cancelled])->save();
            }
        }

        $instance->forceFill([
            'status' => ApprovalInstanceStatus::Rejected,
            'current_step_order' => null,
            'completed_at' => $actedAt,
        ])->save();
        $this->updateBusinessStatus($business, 'REJECTED', 'rejected_at', $actedAt);
    }

    /** @param Collection<int, ApprovalAssignment> $currentAssignments */
    private function applyChangesRequested(
        Model $business,
        ApprovalInstance $instance,
        ApprovalAssignment $assignment,
        Collection $currentAssignments,
        \DateTimeInterface $actedAt,
    ): void {
        foreach ($currentAssignments->where('status', ApprovalAssignmentStatus::Pending) as $pendingAssignment) {
            $pendingAssignment->forceFill([
                'status' => ApprovalAssignmentStatus::Cancelled,
                'acted_at' => $pendingAssignment->is($assignment) ? $actedAt : null,
            ])->save();
        }

        $this->updateBusinessStatus($business, 'CHANGES_REQUESTED', null, $actedAt);
        $instance->touch();
    }

    /** @param Collection<int, ApprovalAssignment> $assignments */
    private function markOtherAssignments(
        Collection $assignments,
        ApprovalAssignment $actingAssignment,
        ApprovalAssignmentStatus $status,
    ): void {
        foreach ($assignments as $assignment) {
            if (! $assignment->is($actingAssignment) && $assignment->status === ApprovalAssignmentStatus::Pending) {
                $assignment->forceFill(['status' => $status])->save();
            }
        }
    }

    private function createAssignments(
        ApprovalStepInstance $step,
        ApprovalInstance $instance,
        \DateTimeInterface $assignedAt,
    ): void {
        $approvers = $this->approverResolver->resolve($step, $instance->context());
        $lockedApprovers = User::query()
            ->whereKey($approvers->modelKeys())
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($lockedApprovers->count() !== $approvers->count()
            || $lockedApprovers->contains(fn (User $user): bool => $user->status !== UserStatus::Active
                || $user->id === $instance->context()->requesterId)) {
            throw ApprovalRuntimeException::unavailableApprover();
        }

        foreach ($lockedApprovers as $approver) {
            $step->assignments()->create([
                'approver_id' => $approver->id,
                'status' => ApprovalAssignmentStatus::Pending,
                'assigned_at' => $assignedAt,
            ]);
        }
    }

    private function updateBusinessStatus(
        Model $business,
        string $status,
        ?string $timestampField,
        \DateTimeInterface $transitionedAt,
    ): void {
        $attributes = [
            'status' => $status,
            'lock_version' => (int) $business->getAttribute('lock_version') + 1,
        ];

        if ($timestampField !== null) {
            $attributes[$timestampField] = $transitionedAt;
        }

        $business->forceFill($attributes)->save();
    }

    private function normalizeComment(ApprovalActionType $action, ?string $comment): ?string
    {
        $comment = trim((string) $comment);

        if (in_array($action, [ApprovalActionType::Rejected, ApprovalActionType::ChangesRequested], true)
            && $comment === '') {
            throw ValidationException::withMessages(['comment' => 'A meaningful comment is required.']);
        }

        if (mb_strlen($comment) > 2000) {
            throw ValidationException::withMessages(['comment' => 'The comment may not be greater than 2000 characters.']);
        }

        return $comment === '' ? null : $comment;
    }

    private function staleAction(): ValidationException
    {
        return ValidationException::withMessages([
            'action' => 'This approval is no longer actionable. Refresh the page to see its current state.',
        ]);
    }
}
