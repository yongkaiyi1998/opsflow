<?php

namespace App\AI;

use App\AI\Contracts\StructuredAiSchema;
use App\MasterDataStatus;
use App\Models\SpendCategory;
use Illuminate\Validation\Rule;

final readonly class CategorySuggestionSchema implements StructuredAiSchema
{
    public const PROMPT_VERSION = 'v1';

    public const SCHEMA_VERSION = 'v1';

    /** @param list<int> $allowedCategoryIds */
    public function __construct(private array $allowedCategoryIds) {}

    public function version(): string
    {
        return self::SCHEMA_VERSION;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'category_id' => [
                'required',
                'integer',
                Rule::in($this->allowedCategoryIds),
                Rule::exists(SpendCategory::class, 'id')->where('status', MasterDataStatus::Active->value),
            ],
            'rationale' => ['required', 'string', 'max:500'],
            'confidence_label' => ['sometimes', 'nullable', 'string', Rule::in(['HIGH', 'MEDIUM', 'LOW'])],
        ];
    }
}
