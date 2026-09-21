<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable(['original_name', 'stored_name', 'disk', 'path', 'mime_type', 'size', 'uploaded_by'])]
class Attachment extends Model
{
    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function sourceDocumentIntake(): HasOne
    {
        return $this->hasOne(DocumentIntake::class, 'supplier_invoice_attachment_id');
    }

    public function sourceExpenseReceiptIntake(): HasOne
    {
        return $this->hasOne(DocumentIntake::class, 'expense_item_attachment_id');
    }

    public function sourcePurchaseQuotationIntake(): HasOne
    {
        return $this->hasOne(DocumentIntake::class, 'purchase_request_attachment_id');
    }
}
