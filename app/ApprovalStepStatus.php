<?php

namespace App;

enum ApprovalStepStatus: string
{
    case Waiting = 'WAITING';
    case Active = 'ACTIVE';
    case Completed = 'COMPLETED';
    case Rejected = 'REJECTED';
    case Cancelled = 'CANCELLED';
    case Blocked = 'BLOCKED';
}
