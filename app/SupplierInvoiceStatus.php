<?php

namespace App;

enum SupplierInvoiceStatus: string
{
    case Draft = 'DRAFT';
    case InApproval = 'IN_APPROVAL';
    case ChangesRequested = 'CHANGES_REQUESTED';
    case Approved = 'APPROVED';
    case Rejected = 'REJECTED';
    case Withdrawn = 'WITHDRAWN';
}
