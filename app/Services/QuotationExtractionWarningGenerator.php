<?php

namespace App\Services;

use App\Support\Money;

final class QuotationExtractionWarningGenerator
{
    /**
     * @param  array<string, mixed>  $candidate
     * @return list<array{code: string, message: string}>
     */
    public function generate(array $candidate): array
    {
        $warnings = [];

        if ($candidate['quotation_no'] === null || trim($candidate['quotation_no']) === '') {
            $warnings[] = $this->warning('missing_quotation_number', 'Quotation number was not found.');
        }

        if ($candidate['quotation_date'] === null) {
            $warnings[] = $this->warning('missing_quotation_date', 'Quotation date was not found.');
        }

        if ($candidate['valid_until'] !== null && $candidate['valid_until'] < today()->toDateString()) {
            $warnings[] = $this->warning('quotation_expired', 'The quotation validity date has passed.');
        }

        if ($candidate['currency'] === null) {
            $warnings[] = $this->warning('missing_currency', 'Currency was not found.');
        } elseif ($candidate['currency'] !== 'MYR') {
            $warnings[] = $this->warning('unsupported_currency', 'The extracted currency is not supported by OpsFlow.');
        }

        $lineSubtotals = [];
        $allLineSubtotalsAvailable = $candidate['line_items'] !== [];

        foreach ($candidate['line_items'] as $index => $item) {
            if ($item['quantity'] === null) {
                $warnings[] = $this->warning('missing_quantity', 'Line '.($index + 1).' quantity was not found.');
            }

            $calculated = $item['quantity'] !== null && $item['unit_price'] !== null
                ? Money::of($item['unit_price'])->multiply($item['quantity'])
                : null;

            if ($calculated !== null && $item['subtotal'] !== null && $calculated->compare($item['subtotal']) !== 0) {
                $warnings[] = $this->warning('line_subtotal_mismatch', 'Line '.($index + 1).' subtotal does not match quantity multiplied by unit price.');
            }

            $lineSubtotal = $item['subtotal'] !== null ? Money::of($item['subtotal']) : $calculated;

            if ($lineSubtotal === null) {
                $allLineSubtotalsAvailable = false;
            } else {
                $lineSubtotals[] = $lineSubtotal;
            }
        }

        if ($candidate['subtotal'] !== null && $allLineSubtotalsAvailable
            && Money::sum($lineSubtotals)->compare($candidate['subtotal']) !== 0) {
            $warnings[] = $this->warning('subtotal_mismatch', 'Subtotal does not match the extracted line items.');
        }

        if ($candidate['subtotal'] !== null && $candidate['tax_amount'] !== null && $candidate['total_amount'] !== null
            && Money::of($candidate['subtotal'])->add($candidate['tax_amount'])->compare($candidate['total_amount']) !== 0) {
            $warnings[] = $this->warning('total_mismatch', 'Subtotal plus tax does not match the extracted total.');
        }

        return $warnings;
    }

    /** @return array{code: string, message: string} */
    private function warning(string $code, string $message): array
    {
        return compact('code', 'message');
    }
}
