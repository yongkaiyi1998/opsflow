<?php

namespace App\Models;

use App\ExpenseClaimStatus;
use App\Models\Concerns\HasAttachments;
use Database\Factories\ExpenseClaimFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use LogicException;

#[Fillable(['title', 'description'])]
class ExpenseClaim extends Model
{
    /** @use HasFactory<ExpenseClaimFactory> */
    use HasAttachments, HasFactory;

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ExpenseItem::class)->orderBy('id');
    }

    public function approvalInstances(): MorphMany
    {
        return $this->morphMany(ApprovalInstance::class, 'approvable')->latest('id');
    }

    public function isDraft(): bool
    {
        return $this->status === ExpenseClaimStatus::Draft;
    }

    protected function casts(): array
    {
        return [
            'total_amount' => 'decimal:2',
            'status' => ExpenseClaimStatus::class,
            'lock_version' => 'integer',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'withdrawn_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function (self $claim): void {
            if (! $claim->isDraft() || $claim->approvalInstances()->exists()) {
                throw new LogicException('Only a never-submitted draft expense claim may be deleted.');
            }
        });
    }
}
