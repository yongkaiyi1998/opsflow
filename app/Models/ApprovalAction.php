<?php

namespace App\Models;

use App\ApprovalActionType;
use Database\Factories\ApprovalActionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable(['approval_instance_id', 'approval_step_instance_id', 'actor_id', 'action', 'comment', 'metadata', 'created_at'])]
class ApprovalAction extends Model
{
    /** @use HasFactory<ApprovalActionFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    public function approvalInstance(): BelongsTo
    {
        return $this->belongsTo(ApprovalInstance::class);
    }

    public function step(): BelongsTo
    {
        return $this->belongsTo(ApprovalStepInstance::class, 'approval_step_instance_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    protected function casts(): array
    {
        return [
            'action' => ApprovalActionType::class,
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Approval action history cannot be changed.');
        });
        static::deleting(function (): never {
            throw new LogicException('Approval action history cannot be deleted.');
        });
    }
}
