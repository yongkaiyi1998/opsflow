<?php

namespace App\Models;

use App\ApprovalAssignmentStatus;
use Database\Factories\ApprovalAssignmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable([
    'approval_step_instance_id', 'approver_id', 'status', 'assigned_at', 'acted_at', 'delegated_from_user_id',
])]
class ApprovalAssignment extends Model
{
    /** @use HasFactory<ApprovalAssignmentFactory> */
    use HasFactory;

    public function step(): BelongsTo
    {
        return $this->belongsTo(ApprovalStepInstance::class, 'approval_step_instance_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_id');
    }

    public function delegatedFrom(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delegated_from_user_id');
    }

    protected function casts(): array
    {
        return [
            'status' => ApprovalAssignmentStatus::class,
            'assigned_at' => 'datetime',
            'acted_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function (): never {
            throw new LogicException('Approval runtime history cannot be deleted.');
        });
    }
}
