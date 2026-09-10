<?php

namespace App\Models;

use App\ExpenseClaimStatus;
use App\Models\Concerns\HasAttachments;
use Database\Factories\ExpenseItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable(['category_id', 'expense_date', 'merchant', 'description', 'amount', 'tax_amount'])]
class ExpenseItem extends Model
{
    /** @use HasFactory<ExpenseItemFactory> */
    use HasAttachments, HasFactory;

    public function expenseClaim(): BelongsTo
    {
        return $this->belongsTo(ExpenseClaim::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(SpendCategory::class, 'category_id');
    }

    protected function casts(): array
    {
        return [
            'expense_date' => 'date',
            'amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'receipt_required' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $item): void {
            $item->ensureDraftClaim();
        });
        static::deleting(function (self $item): void {
            $item->ensureDraftClaim();
        });
    }

    private function ensureDraftClaim(): void
    {
        if (! ExpenseClaim::query()->whereKey($this->expense_claim_id)->where('status', ExpenseClaimStatus::Draft->value)->exists()) {
            throw new LogicException('Submitted expense claim items cannot be changed.');
        }
    }
}
