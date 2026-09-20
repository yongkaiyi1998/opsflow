<?php

namespace App\Support;

use Illuminate\Support\Str;

class MatchingNormalizer
{
    /** @var list<string> */
    private const LEGAL_SUFFIXES = [
        'berhad', 'bhd', 'company', 'co', 'corporation', 'corp', 'inc', 'limited', 'llc', 'ltd', 'plc', 'sdn',
    ];

    public function text(?string $value): string
    {
        if ($value === null) {
            return '';
        }

        $normalized = Str::ascii(Str::lower(Str::squish($value)));
        $normalized = str_replace('&', ' and ', $normalized);
        $normalized = preg_replace('/[^a-z0-9]+/', ' ', $normalized) ?? '';

        return Str::squish($normalized);
    }

    public function compact(?string $value): string
    {
        return str_replace(' ', '', $this->text($value));
    }

    public function vendorName(?string $value): string
    {
        $tokens = array_values(array_filter(
            explode(' ', $this->text($value)),
            fn (string $token): bool => ! in_array($token, self::LEGAL_SUFFIXES, true),
        ));

        return implode(' ', $tokens);
    }

    public function invoiceNumber(?string $value): string
    {
        return $this->compact($value);
    }

    public function similarity(?string $left, ?string $right): int
    {
        $left = $this->compact($left);
        $right = $this->compact($right);

        if ($left === '' || $right === '') {
            return 0;
        }

        if ($left === $right) {
            return 100;
        }

        $maximumLength = max(strlen($left), strlen($right));
        $distance = min(levenshtein($left, $right), $maximumLength);

        return intdiv(($maximumLength - $distance) * 100, $maximumLength);
    }
}
