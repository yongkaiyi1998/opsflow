<?php

namespace App\Services;

use App\ReferenceType;
use Illuminate\Support\Facades\DB;
use OverflowException;

class ReferenceNumberGenerator
{
    private const MAX_SEQUENCE = 999999;

    public function next(ReferenceType $type, ?int $year = null): string
    {
        $year ??= (int) now()->format('Y');

        if ($year < 1 || $year > 9999) {
            throw new \InvalidArgumentException('The reference year must contain between one and four digits.');
        }

        return DB::transaction(function () use ($type, $year): string {
            $timestamp = now();
            DB::table('reference_sequences')->insertOrIgnore([
                'type' => $type->value,
                'year' => $year,
                'last_number' => 0,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);

            $sequence = DB::table('reference_sequences')
                ->where('type', $type->value)
                ->where('year', $year)
                ->lockForUpdate()
                ->first();

            $nextNumber = ((int) $sequence->last_number) + 1;
            if ($nextNumber > self::MAX_SEQUENCE) {
                throw new OverflowException("The {$type->value} reference sequence for {$year} is exhausted.");
            }

            DB::table('reference_sequences')->where('id', $sequence->id)->update([
                'last_number' => $nextNumber,
                'updated_at' => now(),
            ]);

            return sprintf('%s-%04d-%06d', $type->value, $year, $nextNumber);
        }, 5);
    }
}
