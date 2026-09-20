<?php

namespace App;

enum DocumentIntakeStatus: string
{
    case Pending = 'PENDING';
    case Processing = 'PROCESSING';
    case NeedsVerification = 'NEEDS_VERIFICATION';
    case Verified = 'VERIFIED';
    case Failed = 'FAILED';
    case Skipped = 'SKIPPED';
}
