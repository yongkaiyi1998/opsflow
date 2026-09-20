<?php

namespace App\AI;

use App\AI\Contracts\StructuredAiSchema;
use Closure;
use Illuminate\Validation\Rule;

final class RecordAnalysisSchema implements StructuredAiSchema
{
    public const PROMPT_VERSION = 'v1';

    public const SCHEMA_VERSION = 'v1';

    public function version(): string
    {
        return self::SCHEMA_VERSION;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $noDecisionRecommendation = static function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value)) {
                return;
            }

            $containsDecision = preg_match('/\b(?:approve|approved|approval|reject|rejected|rejection|request changes)\b/i', $value) === 1;
            $containsRecommendation = preg_match('/\b(?:should|must|recommend|recommends|recommended|recommendation)\b/i', $value) === 1;

            if (($containsDecision && $containsRecommendation)
                || preg_match('/^\s*(?:approve|reject|request changes)\b/i', $value) === 1) {
                $fail('AI analysis must not recommend an approval decision.');
            }
        };

        return [
            'headline' => ['present', 'nullable', 'string', 'max:160', $noDecisionRecommendation],
            'bullets' => ['required', 'array', 'min:3', 'max:6'],
            'bullets.*' => ['required', 'string', 'max:300', $noDecisionRecommendation],
            'flags' => ['present', 'array', 'max:5'],
            'flags.*' => ['required', 'array:title,explanation,severity_label'],
            'flags.*.title' => ['required', 'string', 'max:120', $noDecisionRecommendation],
            'flags.*.explanation' => ['required', 'string', 'max:400', $noDecisionRecommendation],
            'flags.*.severity_label' => ['required', 'string', Rule::in(['INFO', 'REVIEW', 'IMPORTANT'])],
        ];
    }
}
