<?php

namespace App\Services;

use App\MasterDataStatus;
use App\Models\Vendor;
use App\Support\MatchingNormalizer;
use App\VendorMatchClassification;

class VendorMatchingService
{
    private const LIKELY_SIMILARITY = 85;

    private const POSSIBLE_SIMILARITY = 60;

    public function __construct(private readonly MatchingNormalizer $normalizer) {}

    /**
     * @return list<array{vendor: Vendor, classification: VendorMatchClassification, score: int, reasons: list<string>}>
     */
    public function match(?string $candidateName, int $limit = 5): array
    {
        $candidate = $this->normalizer->text($candidateName);
        $candidateCore = $this->normalizer->vendorName($candidateName);

        if ($candidate === '' || $candidateCore === '') {
            return [];
        }

        return Vendor::query()
            ->where('status', MasterDataStatus::Active->value)
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name', 'code', 'email', 'phone', 'status'])
            ->map(function (Vendor $vendor) use ($candidate, $candidateCore): ?array {
                $vendorName = $this->normalizer->text($vendor->name);
                $vendorCore = $this->normalizer->vendorName($vendor->name);

                if ($candidate === $vendorName) {
                    return $this->result($vendor, VendorMatchClassification::Likely, 100, 'Normalized vendor name matches exactly.');
                }

                if ($candidateCore === $vendorCore) {
                    return $this->result($vendor, VendorMatchClassification::Likely, 95, 'Vendor name matches after legal suffix normalization.');
                }

                $similarity = $this->normalizer->similarity($candidateCore, $vendorCore);

                if ($similarity >= self::LIKELY_SIMILARITY) {
                    return $this->result($vendor, VendorMatchClassification::Likely, $similarity, 'Vendor name is highly similar.');
                }

                if ($similarity >= self::POSSIBLE_SIMILARITY) {
                    return $this->result($vendor, VendorMatchClassification::Possible, $similarity, 'Vendor name is similar.');
                }

                return null;
            })
            ->filter()
            ->sortBy([
                ['score', 'desc'],
                [fn (array $match): string => $match['vendor']->name, 'asc'],
                [fn (array $match): int => $match['vendor']->id, 'asc'],
            ])
            ->take(max(1, min($limit, 10)))
            ->values()
            ->all();
    }

    /** @return array{vendor: Vendor, classification: VendorMatchClassification, score: int, reasons: list<string>} */
    private function result(Vendor $vendor, VendorMatchClassification $classification, int $score, string $reason): array
    {
        return compact('vendor', 'classification', 'score') + ['reasons' => [$reason]];
    }
}
