<?php

namespace App\Models;

use App\ApprovalInstanceStatus;
use App\WorkflowContext;
use Database\Factories\ApprovalInstanceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LogicException;

#[Fillable([
    'approvable_type', 'approvable_id', 'workflow_version_id', 'workflow_rule_group_id',
    'status', 'current_step_order', 'workflow_context', 'started_at', 'completed_at',
])]
class ApprovalInstance extends Model
{
    /** @use HasFactory<ApprovalInstanceFactory> */
    use HasFactory;

    public function approvable(): MorphTo
    {
        return $this->morphTo();
    }

    public function workflowVersion(): BelongsTo
    {
        return $this->belongsTo(WorkflowVersion::class);
    }

    public function workflowRuleGroup(): BelongsTo
    {
        return $this->belongsTo(WorkflowRuleGroup::class);
    }

    public function steps(): HasMany
    {
        return $this->hasMany(ApprovalStepInstance::class)->orderBy('step_order');
    }

    public function actions(): HasMany
    {
        return $this->hasMany(ApprovalAction::class)->orderBy('created_at')->orderBy('id');
    }

    public function context(): WorkflowContext
    {
        return WorkflowContext::fromSnapshot($this->workflow_context);
    }

    protected function casts(): array
    {
        return [
            'status' => ApprovalInstanceStatus::class,
            'workflow_context' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function (): never {
            throw new LogicException('Approval runtime history cannot be deleted.');
        });
    }
}
