<?php

namespace App;

enum IntakeDocumentType: string
{
    case SupplierInvoice = 'SUPPLIER_INVOICE';
    case ExpenseReceipt = 'EXPENSE_RECEIPT';
    case PurchaseQuotation = 'PURCHASE_QUOTATION';
}
