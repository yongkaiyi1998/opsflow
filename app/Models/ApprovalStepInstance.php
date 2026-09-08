<?php

namespace App\Models;

use App\ApprovalMode;
use App\ApprovalStepStatus;
use App\ApproverType;
use Database\Factories\ApprovalStepInstanceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

#[Fillable([
    'approval_instance_id', 'workflow_step_id', 'step_order', 'name', 'approver_type',
    'approver_value', 'approval_mode', 'required_approvals', 'status', 'started_at', 'completed_at',
])]
class ApprovalStepInstance extends Model
{
    /** @use HasFactory<ApprovalStepInstanceFactory> */
    use HasFactory;

    public function approvalInstance(): BelongsTo
    {
        return $this->belongsTo(ApprovalInstance::class);
    }

    public function workflowStep(): BelongsTo
    {
        return $this->belongsTo(WorkflowStep::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(ApprovalAssignment::class)->orderBy('id');
    }

    public function actions(): HasMany
    {
        return $this->hasMany(ApprovalAction::class)->orderBy('created_at')->orderBy('id');
    }

    protected function casts(): array
    {
        return [
            'approver_type' => ApproverType::class,
            'approval_mode' => ApprovalMode::class,
            'status' => ApprovalStepStatus::class,
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
