<?php

namespace App\Models;

use App\Models\Concerns\HasAttachments;
use App\SupplierInvoiceStatus;
use Database\Factories\SupplierInvoiceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use LogicException;

#[Fillable(['invoice_no', 'vendor_id', 'department_id', 'category_id', 'invoice_date', 'due_date', 'description', 'tax_amount'])]
class SupplierInvoice extends Model
{
    /** @use HasFactory<SupplierInvoiceFactory> */
    use HasAttachments, HasFactory;

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(SpendCategory::class, 'category_id');
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SupplierInvoiceItem::class)->orderBy('id');
    }

    public function approvalInstances(): MorphMany
    {
        return $this->morphMany(ApprovalInstance::class, 'approvable')->latest('id');
    }

    public function isDraft(): bool
    {
        return $this->status === SupplierInvoiceStatus::Draft;
    }

    protected function casts(): array
    {
        return [
            'invoice_date' => 'date',
            'due_date' => 'date',
            'subtotal' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'status' => SupplierInvoiceStatus::class,
            'lock_version' => 'integer',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'withdrawn_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function (self $invoice): void {
            if (! $invoice->isDraft() || $invoice->approvalInstances()->exists()) {
                throw new LogicException('Only a never-submitted draft supplier invoice may be deleted.');
            }
        });
    }
}
