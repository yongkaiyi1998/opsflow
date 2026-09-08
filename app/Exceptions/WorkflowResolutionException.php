<?php

namespace App\Exceptions;

use RuntimeException;

class WorkflowResolutionException extends RuntimeException
{
    public static function noRoute(): self
    {
        return new self('No approval workflow is configured for this request. Please contact an administrator.');
    }
}
