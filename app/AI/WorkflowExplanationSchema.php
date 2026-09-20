<?php

namespace App\AI;

use App\AI\Contracts\StructuredAiSchema;
use Closure;
use Illuminate\Validation\Rule;

final readonly class WorkflowExplanationSchema implements StructuredAiSchema
{
    public const PROMPT_VERSION = 'v1';

    public const SCHEMA_VERSION = 'v1';

    public function __construct(private int $workflowVersion, private string $ruleGroup, private string $currentStep, private string $assignmentBasis) {}

    public function version(): string
    {
        return self::SCHEMA_VERSION;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $safe = static function (string $attribute, mixed $value, Closure $fail): void {
            if (is_string($value) && preg_match('/^\s*(?:approve|reject|request changes)\b|\b(?:should|must|recommend(?:ed|ation|s)?)\b.{0,35}\b(?:approve|reject|request changes)\b|\b(?:bypass|skip|override)\b.{0,25}\bworkflow\b/i', $value) === 1) {
                $fail('The workflow explanation must not recommend a decision or bypass workflow.');
            }
        };

        return [
            'workflow_version' => ['required', 'integer', Rule::in([$this->workflowVersion])],
            'rule_group' => ['required', 'string', Rule::in([$this->ruleGroup])],
            'current_step' => ['required', 'string', Rule::in([$this->currentStep])],
            'assignment_basis' => ['required', 'string', Rule::in([$this->assignmentBasis])],
            'headline' => ['required', 'string', 'max:160', $safe],
            'explanation' => ['required', 'string', 'max:700', $safe],
            'key_points' => ['required', 'array', 'min:1', 'max:4'],
            'key_points.*' => ['required', 'string', 'max:220', $safe],
        ];
    }
}
