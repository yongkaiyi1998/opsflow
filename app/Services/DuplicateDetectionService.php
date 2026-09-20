<?php

namespace App\Services;

use App\DocumentIntakeStatus;
use App\DuplicateMatchClassification;
use App\Models\DocumentIntake;
use App\Models\SupplierInvoice;
use App\Models\Vendor;
use App\Support\MatchingNormalizer;
use App\Support\Money;
use App\VendorMatchClassification;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;
use OverflowException;

class DuplicateDetectionService
{
    private const HIGH_SCORE = 70;

    private const MEDIUM_SCORE = 40;

    private const STRONG_VENDOR_SIMILARITY = 90;

    private const POSSIBLE_VENDOR_SIMILARITY = 60;

    private const HIGH_INVOICE_NUMBER_SIMILARITY = 85;

    private const POSSIBLE_INVOICE_NUMBER_SIMILARITY = 65;

    private const HIGH_DESCRIPTION_SIMILARITY = 75;

    private const POSSIBLE_DESCRIPTION_SIMILARITY = 60;

    private const MAX_INVOICE_CANDIDATES = 500;

    private const MAX_PREVIOUS_INTAKES = 250;

    public function __construct(
        private readonly MatchingNormalizer $normalizer,
        private readonly VendorMatchingService $vendorMatching,
    ) {}

    /**
     * @param  list<array{vendor: Vendor, classification: VendorMatchClassification, score: int, reasons: list<string>}>|null  $vendorMatches
     * @return list<array{classification: DuplicateMatchClassification, score: int, reasons: list<string>, record_type: string, record: SupplierInvoice|DocumentIntake, invoice_no: ?string, vendor_name: ?string, total_amount: ?string, invoice_date: ?string}>
     */
    public function analyze(DocumentIntake $intake, ?int $selectedVendorId = null, ?array $vendorMatches = null): array
    {
        $candidate = $this->intakeCandidate($intake);

        if (! $this->hasUsefulCandidate($candidate)) {
            return [];
        }

        $vendorMatches ??= $this->vendorMatching->match($candidate['vendor_name']);
        $vendorScores = collect($vendorMatches)->mapWithKeys(
            fn (array $match): array => [$match['vendor']->id => $match['score']],
        )->all();

        if ($selectedVendorId !== null) {
            $vendorScores[$selectedVendorId] = 100;
        }

        $matches = [];

        foreach ($this->invoiceCandidates($candidate, array_keys($vendorScores)) as $invoice) {
            $match = $this->evaluate(
                $candidate,
                $this->invoiceCandidate($invoice),
                $vendorScores[$invoice->vendor_id] ?? 0,
                true,
                'supplier_invoice',
                $invoice,
            );

            if ($match !== null) {
                $matches[] = $match;
            }
        }

        foreach ($this->intakeCandidates($intake) as $otherIntake) {
            $otherCandidate = $this->intakeCandidate($otherIntake);
            $vendorSimilarity = $this->vendorSimilarity($candidate['vendor_name'], $otherCandidate['vendor_name']);
            $match = $this->evaluate(
                $candidate,
                $otherCandidate,
                $vendorSimilarity,
                false,
                'document_intake',
                $otherIntake,
            );

            if ($match !== null) {
                $matches[] = $match;
            }
        }

        usort($matches, function (array $left, array $right): int {
            return $right['classification']->rank() <=> $left['classification']->rank()
                ?: $right['score'] <=> $left['score']
                ?: strcmp($left['record_type'], $right['record_type'])
                ?: $left['record']->id <=> $right['record']->id;
        });

        return array_slice($matches, 0, 8);
    }

    public function findExactSupplierInvoice(int $vendorId, string $invoiceNumber): ?SupplierInvoice
    {
        $normalizedInvoiceNumber = $this->normalizer->invoiceNumber($invoiceNumber);

        if ($vendorId < 1 || $normalizedInvoiceNumber === '') {
            return null;
        }

        return SupplierInvoice::query()
            ->where('vendor_id', $vendorId)
            ->orderBy('id')
            ->lazyById(200)
            ->first(fn (SupplierInvoice $invoice): bool => $this->normalizer->invoiceNumber($invoice->invoice_no) === $normalizedInvoiceNumber);
    }

    /**
     * @param  array{vendor_name: ?string, invoice_no: ?string, total_amount: ?string, invoice_date: ?string, description_text: string}  $candidate
     * @param  list<int>  $vendorIds
     * @return Collection<int, SupplierInvoice>
     */
    private function invoiceCandidates(array $candidate, array $vendorIds): Collection
    {
        $query = SupplierInvoice::query()->with(['vendor:id,name', 'items:id,supplier_invoice_id,description']);
        $hasConstraint = false;

        $query->where(function (Builder $query) use ($candidate, $vendorIds, &$hasConstraint): void {
            if ($vendorIds !== []) {
                $query->whereIn('vendor_id', $vendorIds);
                $hasConstraint = true;
            }

            if ($candidate['total_amount'] !== null) {
                $method = $hasConstraint ? 'orWhere' : 'where';
                $query->{$method}('total_amount', $candidate['total_amount']);
                $hasConstraint = true;
            }

            $date = $this->date($candidate['invoice_date']);

            if ($date !== null) {
                $method = $hasConstraint ? 'orWhereBetween' : 'whereBetween';
                $query->{$method}('invoice_date', [$date->subDays(7)->toDateString(), $date->addDays(7)->toDateString()]);
                $hasConstraint = true;
            }
        });

        if (! $hasConstraint) {
            return SupplierInvoice::query()->whereRaw('1 = 0')->get();
        }

        return $query->latest('id')->limit(self::MAX_INVOICE_CANDIDATES)->get();
    }

    /** @return Collection<int, DocumentIntake> */
    private function intakeCandidates(DocumentIntake $intake): Collection
    {
        $sameBatch = DocumentIntake::query()
            ->with('intakeBatch:id')
            ->where('intake_batch_id', $intake->intake_batch_id)
            ->whereKeyNot($intake->id)
            ->whereIn('status', [DocumentIntakeStatus::NeedsVerification->value, DocumentIntakeStatus::Verified->value])
            ->whereNull('supplier_invoice_id')
            ->whereNotNull('extraction_payload')
            ->orderBy('id')
            ->get();

        $previous = DocumentIntake::query()
            ->with('intakeBatch:id')
            ->where('intake_batch_id', '!=', $intake->intake_batch_id)
            ->whereIn('status', [DocumentIntakeStatus::NeedsVerification->value, DocumentIntakeStatus::Verified->value])
            ->whereNull('supplier_invoice_id')
            ->whereNotNull('extraction_payload')
            ->latest('id')
            ->limit(self::MAX_PREVIOUS_INTAKES)
            ->get();

        return $sameBatch->concat($previous)->unique('id')->values();
    }

    /**
     * @param  array{vendor_name: ?string, invoice_no: ?string, total_amount: ?string, invoice_date: ?string, description_text: string}  $candidate
     * @param  array{vendor_name: ?string, invoice_no: ?string, total_amount: ?string, invoice_date: ?string, description_text: string}  $other
     * @return array{classification: DuplicateMatchClassification, score: int, reasons: list<string>, record_type: string, record: SupplierInvoice|DocumentIntake, invoice_no: ?string, vendor_name: ?string, total_amount: ?string, invoice_date: ?string}|null
     */
    private function evaluate(
        array $candidate,
        array $other,
        int $vendorSimilarity,
        bool $canBeExact,
        string $recordType,
        SupplierInvoice|DocumentIntake $record,
    ): ?array {
        $score = 0;
        $reasons = [];

        if ($vendorSimilarity >= self::STRONG_VENDOR_SIMILARITY) {
            $score += 30;
            $reasons[] = 'Vendor matched.';
        } elseif ($vendorSimilarity >= self::POSSIBLE_VENDOR_SIMILARITY) {
            $score += 18;
            $reasons[] = 'Vendor name is similar.';
        }

        $invoiceSimilarity = $this->normalizer->similarity($candidate['invoice_no'], $other['invoice_no']);

        if ($invoiceSimilarity === 100) {
            $score += 40;
            $reasons[] = 'Invoice number matches after normalization.';
        } elseif ($invoiceSimilarity >= self::HIGH_INVOICE_NUMBER_SIMILARITY) {
            $score += 25;
            $reasons[] = 'Invoice number is highly similar.';
        } elseif ($invoiceSimilarity >= self::POSSIBLE_INVOICE_NUMBER_SIMILARITY) {
            $score += 12;
            $reasons[] = 'Invoice number is similar.';
        }

        [$amountScore, $amountReason] = $this->amountEvidence($candidate['total_amount'], $other['total_amount']);
        $score += $amountScore;

        if ($amountReason !== null) {
            $reasons[] = $amountReason;
        }

        [$dateScore, $dateReason] = $this->dateEvidence($candidate['invoice_date'], $other['invoice_date']);
        $score += $dateScore;

        if ($dateReason !== null) {
            $reasons[] = $dateReason;
        }

        $descriptionSimilarity = $this->normalizer->similarity($candidate['description_text'], $other['description_text']);

        if ($descriptionSimilarity >= self::HIGH_DESCRIPTION_SIMILARITY) {
            $score += 10;
            $reasons[] = 'Line-item descriptions are highly similar.';
        } elseif ($descriptionSimilarity >= self::POSSIBLE_DESCRIPTION_SIMILARITY) {
            $score += 5;
            $reasons[] = 'Line-item descriptions are similar.';
        }

        $classification = match (true) {
            $canBeExact && $vendorSimilarity >= self::STRONG_VENDOR_SIMILARITY && $invoiceSimilarity === 100 => DuplicateMatchClassification::Exact,
            $score >= self::HIGH_SCORE => DuplicateMatchClassification::High,
            $score >= self::MEDIUM_SCORE => DuplicateMatchClassification::Medium,
            default => DuplicateMatchClassification::None,
        };

        if ($classification === DuplicateMatchClassification::None) {
            return null;
        }

        return [
            'classification' => $classification,
            'score' => $score,
            'reasons' => $reasons,
            'record_type' => $recordType,
            'record' => $record,
            'invoice_no' => $other['invoice_no'],
            'vendor_name' => $other['vendor_name'],
            'total_amount' => $other['total_amount'],
            'invoice_date' => $other['invoice_date'],
        ];
    }

    /** @return array{int, ?string} */
    private function amountEvidence(?string $left, ?string $right): array
    {
        $leftMoney = $this->money($left);
        $rightMoney = $this->money($right);

        if ($leftMoney === null || $rightMoney === null) {
            return [0, null];
        }

        if ($leftMoney->compare($rightMoney) === 0) {
            return [20, 'Total amount matches exactly.'];
        }

        $difference = abs($leftMoney->minorUnits() - $rightMoney->minorUnits());
        $basis = max(abs($leftMoney->minorUnits()), abs($rightMoney->minorUnits()), 1);

        if ($difference * 100 <= $basis) {
            return [15, 'Total amount differs by no more than 1%.'];
        }

        if ($difference * 20 <= $basis) {
            return [5, 'Total amount differs by no more than 5%.'];
        }

        return [0, null];
    }

    /** @return array{int, ?string} */
    private function dateEvidence(?string $left, ?string $right): array
    {
        $leftDate = $this->date($left);
        $rightDate = $this->date($right);

        if ($leftDate === null || $rightDate === null) {
            return [0, null];
        }

        $days = (int) $leftDate->diffInDays($rightDate, true);

        return match (true) {
            $days === 0 => [10, 'Invoice date matches exactly.'],
            $days <= 3 => [8, "Invoice date differs by {$days} ".($days === 1 ? 'day.' : 'days.')],
            $days <= 7 => [5, "Invoice date differs by {$days} days."],
            default => [0, null],
        };
    }

    private function vendorSimilarity(?string $left, ?string $right): int
    {
        $leftCore = $this->normalizer->vendorName($left);
        $rightCore = $this->normalizer->vendorName($right);

        if ($leftCore !== '' && $leftCore === $rightCore) {
            return 95;
        }

        return $this->normalizer->similarity($leftCore, $rightCore);
    }

    /** @return array{vendor_name: ?string, invoice_no: ?string, total_amount: ?string, invoice_date: ?string, description_text: string} */
    private function intakeCandidate(DocumentIntake $intake): array
    {
        $payload = is_array($intake->extraction_payload) ? $intake->extraction_payload : [];

        return [
            'vendor_name' => $this->nullableString($payload['vendor_name'] ?? null),
            'invoice_no' => $this->nullableString($payload['invoice_no'] ?? null),
            'total_amount' => $this->nullableString($payload['total_amount'] ?? null),
            'invoice_date' => $this->nullableString($payload['invoice_date'] ?? null),
            'description_text' => $this->descriptionText($payload['line_items'] ?? []),
        ];
    }

    /** @return array{vendor_name: ?string, invoice_no: ?string, total_amount: ?string, invoice_date: ?string, description_text: string} */
    private function invoiceCandidate(SupplierInvoice $invoice): array
    {
        return [
            'vendor_name' => $invoice->vendor?->name,
            'invoice_no' => $invoice->invoice_no,
            'total_amount' => $invoice->total_amount,
            'invoice_date' => $invoice->invoice_date?->toDateString(),
            'description_text' => $this->descriptionText($invoice->items->all()),
        ];
    }

    private function descriptionText(mixed $items): string
    {
        if (! is_iterable($items)) {
            return '';
        }

        $descriptions = [];

        foreach ($items as $item) {
            $description = is_array($item) ? ($item['description'] ?? null) : ($item->description ?? null);

            if (is_string($description) && trim($description) !== '') {
                $descriptions[] = trim($description);
            }
        }

        return implode(' ', $descriptions);
    }

    /** @param array{vendor_name: ?string, invoice_no: ?string, total_amount: ?string, invoice_date: ?string, description_text: string} $candidate */
    private function hasUsefulCandidate(array $candidate): bool
    {
        return collect($candidate)->contains(fn (?string $value): bool => $value !== null && $value !== '');
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function money(?string $value): ?Money
    {
        if ($value === null) {
            return null;
        }

        try {
            return Money::of($value);
        } catch (InvalidArgumentException|OverflowException) {
            return null;
        }
    }

    private function date(?string $value): ?CarbonImmutable
    {
        if ($value === null) {
            return null;
        }

        try {
            return CarbonImmutable::createFromFormat('!Y-m-d', $value) ?: null;
        } catch (InvalidArgumentException) {
            return null;
        }
    }
}
