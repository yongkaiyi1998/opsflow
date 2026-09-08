<?php

namespace App\Models;

use App\ApprovalMode;
use App\ApproverType;
use App\WorkflowVersionStatus;
use Database\Factories\WorkflowStepFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

#[Fillable(['workflow_rule_group_id', 'step_order', 'name', 'approver_type', 'approver_value', 'approval_mode', 'minimum_approvals', 'sla_hours'])]
class WorkflowStep extends Model
{
    /** @use HasFactory<WorkflowStepFactory> */
    use HasFactory;

    public function ruleGroup(): BelongsTo
    {
        return $this->belongsTo(WorkflowRuleGroup::class, 'workflow_rule_group_id');
    }

    public function runtimeSteps(): HasMany
    {
        return $this->hasMany(ApprovalStepInstance::class);
    }

    protected function casts(): array
    {
        return ['approver_type' => ApproverType::class, 'approval_mode' => ApprovalMode::class];
    }

    protected static function booted(): void
    {
        static::saving(function (self $step): void {
            $step->ensureDraftVersion();
        });
        static::deleting(function (self $step): void {
            $step->ensureDraftVersion();
        });
    }

    private function ensureDraftVersion(): void
    {
        $isDraft = WorkflowRuleGroup::whereKey($this->workflow_rule_group_id)
            ->whereRelation('version', 'status', WorkflowVersionStatus::Draft->value)->exists();

        if (! $isDraft) {
            throw new LogicException('Published and archived workflow configuration is immutable.');
        }
    }
}
