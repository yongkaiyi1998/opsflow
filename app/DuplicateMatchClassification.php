<?php

namespace App;

enum DuplicateMatchClassification: string
{
    case Exact = 'EXACT';
    case High = 'HIGH';
    case Medium = 'MEDIUM';
    case None = 'NONE';

    public function rank(): int
    {
        return match ($this) {
            self::Exact => 3,
            self::High => 2,
            self::Medium => 1,
            self::None => 0,
        };
    }
}
