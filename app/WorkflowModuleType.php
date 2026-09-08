<?php

namespace App;

enum WorkflowModuleType: string
{
    case PurchaseRequest = 'PURCHASE_REQUEST';
    case SupplierInvoice = 'SUPPLIER_INVOICE';
    case ExpenseClaim = 'EXPENSE_CLAIM';

    public function label(): string
    {
        return match ($this) {
            self::PurchaseRequest => 'Purchase Request',
            self::SupplierInvoice => 'Supplier Invoice',
            self::ExpenseClaim => 'Expense Claim',
        };
    }
}
