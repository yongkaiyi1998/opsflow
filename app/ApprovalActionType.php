<?php

namespace App;

enum ApprovalActionType: string
{
    case Submitted = 'SUBMITTED';
    case Assigned = 'ASSIGNED';
    case Approved = 'APPROVED';
    case Rejected = 'REJECTED';
    case ChangesRequested = 'CHANGES_REQUESTED';
    case Resubmitted = 'RESUBMITTED';
    case Withdrawn = 'WITHDRAWN';
    case Delegated = 'DELEGATED';
    case StepCompleted = 'STEP_COMPLETED';
    case WorkflowCompleted = 'WORKFLOW_COMPLETED';
    case WorkflowBlocked = 'WORKFLOW_BLOCKED';
}
