<?php

namespace App;

enum ReferenceType: string
{
    case PurchaseRequest = 'PR';
    case SupplierInvoice = 'INV';
    case ExpenseClaim = 'EXP';
}
