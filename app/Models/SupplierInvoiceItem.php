<?php

namespace App\Models;

use App\SupplierInvoiceStatus;
use Database\Factories\SupplierInvoiceItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable(['description', 'quantity', 'unit_price', 'subtotal'])]
class SupplierInvoiceItem extends Model
{
    /** @use HasFactory<SupplierInvoiceItemFactory> */
    use HasFactory;

    public function supplierInvoice(): BelongsTo
    {
        return $this->belongsTo(SupplierInvoice::class);
    }

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'unit_price' => 'decimal:2',
            'subtotal' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $item): void {
            $item->ensureDraftInvoice();
        });
        static::deleting(function (self $item): void {
            $item->ensureDraftInvoice();
        });
    }

    private function ensureDraftInvoice(): void
    {
        if (! SupplierInvoice::query()
            ->whereKey($this->supplier_invoice_id)
            ->whereIn('status', [SupplierInvoiceStatus::Draft->value, SupplierInvoiceStatus::ChangesRequested->value])
            ->exists()) {
            throw new LogicException('Submitted supplier invoice items cannot be changed.');
        }
    }
}
