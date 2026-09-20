<?php

namespace App\Http\Requests;

class VerifyDocumentIntakeRequest extends StoreSupplierInvoiceRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('verify', $this->route('document_intake')) ?? false;
    }
}
