<?php

namespace App\Exceptions;

use Exception;

class ApprovalRuntimeException extends Exception
{
    public static function unavailableApprover(): self
    {
        return new self('A required approver is unavailable. Please contact an administrator.');
    }

    public static function duplicateRuntime(): self
    {
        return new self('An active approval process already exists for this record.');
    }

    public static function invalidResolution(): self
    {
        return new self('The resolved workflow route is no longer valid. Please try again.');
    }
}
