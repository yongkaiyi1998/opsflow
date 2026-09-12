<?php

namespace App\Models;

use App\PurchaseRequestStatus;
use Database\Factories\PurchaseRequestItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable(['description', 'quantity', 'unit_price', 'subtotal'])]
class PurchaseRequestItem extends Model
{
    /** @use HasFactory<PurchaseRequestItemFactory> */
    use HasFactory;

    public function purchaseRequest(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequest::class);
    }

    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            'subtotal' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $item): void {
            $item->ensureDraftRequest();
        });
        static::deleting(function (self $item): void {
            $item->ensureDraftRequest();
        });
    }

    private function ensureDraftRequest(): void
    {
        if (! PurchaseRequest::query()
            ->whereKey($this->purchase_request_id)
            ->whereIn('status', [PurchaseRequestStatus::Draft->value, PurchaseRequestStatus::ChangesRequested->value])
            ->exists()) {
            throw new LogicException('Submitted purchase request items cannot be changed.');
        }
    }
}
