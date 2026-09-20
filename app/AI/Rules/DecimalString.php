<?php

namespace App\AI\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final class DecimalString implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || preg_match('/^-?(?:0|[1-9]\d*)(?:\.\d+)?$/D', $value) !== 1) {
            $fail("The {$attribute} field must be a plain decimal string.");
        }
    }
}
