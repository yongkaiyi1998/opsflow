<?php

namespace App\Models;

use App\WorkflowRuleField;
use App\WorkflowRuleOperator;
use App\WorkflowVersionStatus;
use Database\Factories\WorkflowRuleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable(['workflow_rule_group_id', 'field', 'operator', 'value'])]
class WorkflowRule extends Model
{
    /** @use HasFactory<WorkflowRuleFactory> */
    use HasFactory;

    public function ruleGroup(): BelongsTo
    {
        return $this->belongsTo(WorkflowRuleGroup::class, 'workflow_rule_group_id');
    }

    protected function casts(): array
    {
        return ['field' => WorkflowRuleField::class, 'operator' => WorkflowRuleOperator::class, 'value' => 'json'];
    }

    protected static function booted(): void
    {
        static::saving(function (self $rule): void {
            $rule->ensureDraftVersion();
        });
        static::deleting(function (self $rule): void {
            $rule->ensureDraftVersion();
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
