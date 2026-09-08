<?php

namespace App;

enum ApprovalAssignmentStatus: string
{
    case Pending = 'PENDING';
    case Approved = 'APPROVED';
    case Rejected = 'REJECTED';
    case Skipped = 'SKIPPED';
    case Delegated = 'DELEGATED';
    case Cancelled = 'CANCELLED';
}
