<?php

namespace App\AI;

use App\Models\AiInteraction;
use App\Models\SpendCategory;

final readonly class CategorySuggestion
{
    public function __construct(
        public SpendCategory $category,
        public string $rationale,
        public ?string $confidenceLabel,
        public AiInteraction $interaction,
    ) {}

    /** @return array{category_id: int, category_name: string, rationale: string, confidence_label: string|null, target: string} */
    public function forFormTarget(string $target): array
    {
        return [
            'category_id' => $this->category->getKey(),
            'category_name' => $this->category->name,
            'rationale' => $this->rationale,
            'confidence_label' => $this->confidenceLabel,
            'target' => $target,
        ];
    }
}
