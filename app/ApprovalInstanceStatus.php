<?php

namespace App;

enum ApprovalInstanceStatus: string
{
    case InProgress = 'IN_PROGRESS';
    case Approved = 'APPROVED';
    case Rejected = 'REJECTED';
    case Cancelled = 'CANCELLED';
    case Blocked = 'BLOCKED';
}
