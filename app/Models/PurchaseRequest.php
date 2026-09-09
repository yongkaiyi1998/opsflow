<?php

namespace App\Models;

use App\Models\Concerns\HasAttachments;
use App\PurchaseRequestStatus;
use Database\Factories\PurchaseRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use LogicException;

#[Fillable(['vendor_id', 'category_id', 'title', 'description', 'tax_amount', 'needed_by_date'])]
class PurchaseRequest extends Model
{
    /** @use HasFactory<PurchaseRequestFactory> */
    use HasAttachments, HasFactory;

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(SpendCategory::class, 'category_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseRequestItem::class)->orderBy('id');
    }

    public function approvalInstances(): MorphMany
    {
        return $this->morphMany(ApprovalInstance::class, 'approvable')->latest('id');
    }

    public function isDraft(): bool
    {
        return $this->status === PurchaseRequestStatus::Draft;
    }

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'needed_by_date' => 'date',
            'status' => PurchaseRequestStatus::class,
            'lock_version' => 'integer',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'withdrawn_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function (self $purchaseRequest): void {
            if (! $purchaseRequest->isDraft() || $purchaseRequest->approvalInstances()->exists()) {
                throw new LogicException('Only a never-submitted draft purchase request may be deleted.');
            }
        });
    }
}
