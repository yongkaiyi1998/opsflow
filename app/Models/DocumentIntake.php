<?php

namespace App\Models;

use App\DocumentIntakeStatus;
use App\IntakeDocumentType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'intake_batch_id', 'original_name', 'disk', 'path', 'mime_type', 'size',
    'document_type', 'status', 'ai_interaction_id', 'extraction_payload',
    'extraction_warnings', 'extraction_prompt_version', 'extraction_schema_version',
    'processing_started_at', 'extracted_at', 'failure_reason', 'supplier_invoice_id',
    'supplier_invoice_attachment_id', 'expense_item_id', 'expense_item_attachment_id',
    'verified_by', 'verified_at',
])]
class DocumentIntake extends Model
{
    protected $attributes = [
        'document_type' => 'SUPPLIER_INVOICE',
        'status' => 'PENDING',
    ];

    public function intakeBatch(): BelongsTo
    {
        return $this->belongsTo(IntakeBatch::class);
    }

    public function aiInteraction(): BelongsTo
    {
        return $this->belongsTo(AiInteraction::class);
    }

    public function supplierInvoice(): BelongsTo
    {
        return $this->belongsTo(SupplierInvoice::class);
    }

    public function supplierInvoiceAttachment(): BelongsTo
    {
        return $this->belongsTo(Attachment::class, 'supplier_invoice_attachment_id');
    }

    public function expenseItem(): BelongsTo
    {
        return $this->belongsTo(ExpenseItem::class);
    }

    public function expenseItemAttachment(): BelongsTo
    {
        return $this->belongsTo(Attachment::class, 'expense_item_attachment_id');
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function typeLabel(): string
    {
        return match ($this->mime_type) {
            'application/pdf' => 'PDF',
            'image/jpeg' => 'JPEG image',
            'image/png' => 'PNG image',
            default => 'Document',
        };
    }

    protected function casts(): array
    {
        return [
            'document_type' => IntakeDocumentType::class,
            'status' => DocumentIntakeStatus::class,
            'size' => 'integer',
            'extraction_payload' => 'array',
            'extraction_warnings' => 'array',
            'processing_started_at' => 'datetime',
            'extracted_at' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }
}
