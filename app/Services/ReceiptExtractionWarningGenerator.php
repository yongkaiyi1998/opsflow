<?php

namespace App\Services;

use App\Support\Money;

class ReceiptExtractionWarningGenerator
{
    /**
     * @param  array<string, mixed>  $candidate
     * @return list<array{code: string, message: string}>
     */
    public function generate(array $candidate): array
    {
        $warnings = [];

        if ($candidate['merchant'] === null || trim($candidate['merchant']) === '') {
            $warnings[] = $this->warning('missing_merchant', 'Merchant was not found.');
        }

        if ($candidate['transaction_date'] === null) {
            $warnings[] = $this->warning('missing_transaction_date', 'Transaction date was not found.');
        } elseif ($candidate['transaction_date'] > today()->toDateString()) {
            $warnings[] = $this->warning('future_transaction_date', 'Transaction date is in the future.');
        }

        if ($candidate['currency'] === null) {
            $warnings[] = $this->warning('missing_currency', 'Currency was not found.');
        } elseif ($candidate['currency'] !== 'MYR') {
            $warnings[] = $this->warning('unsupported_currency', 'The extracted currency is not supported by OpsFlow.');
        }

        if ($candidate['amount'] === null) {
            $warnings[] = $this->warning('missing_amount', 'Gross amount was not found.');
        }

        if (
            $candidate['amount'] !== null
            && $candidate['tax_amount'] !== null
            && Money::of($candidate['tax_amount'])->compare($candidate['amount']) > 0
        ) {
            $warnings[] = $this->warning('tax_exceeds_amount', 'Informational tax is greater than the gross amount.');
        }

        return $warnings;
    }

    /** @return array{code: string, message: string} */
    private function warning(string $code, string $message): array
    {
        return compact('code', 'message');
    }
}
