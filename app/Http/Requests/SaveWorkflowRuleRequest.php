<?php

namespace App\Http\Requests;

use App\Models\Department;
use App\Models\SpendCategory;
use App\Support\Money;
use App\WorkflowRuleField;
use App\WorkflowRuleOperator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SaveWorkflowRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        $subject = $this->route('workflow_rule') ?? $this->route('workflow_rule_group');

        return $this->user()?->can('update', $subject) ?? false;
    }

    public function rules(): array
    {
        return [
            'field' => ['required', Rule::enum(WorkflowRuleField::class)],
            'operator' => ['required', Rule::enum(WorkflowRuleOperator::class)],
            'value' => ['nullable', 'string', 'max:50'],
            'value_ids' => ['nullable', 'array', 'min:1'],
            'value_ids.*' => ['integer', 'distinct'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $field = WorkflowRuleField::tryFrom($this->string('field')->toString());
            $operator = WorkflowRuleOperator::tryFrom($this->string('operator')->toString());

            if ($field === null || $operator === null || ! in_array($operator, $field->operators(), true)) {
                $validator->errors()->add('operator', 'The selected operator is not valid for this field.');

                return;
            }

            if ($field === WorkflowRuleField::Amount) {
                try {
                    if (Money::of($this->string('value')->toString())->compare(0) < 0) {
                        throw new \InvalidArgumentException;
                    }
                } catch (\Throwable) {
                    $validator->errors()->add('value', 'Enter a valid non-negative decimal amount.');
                }

                return;
            }

            $ids = $this->referenceIds($operator);

            if ($ids === []) {
                $validator->errors()->add('value', 'Select at least one valid value.');

                return;
            }

            $model = $field === WorkflowRuleField::Department ? Department::class : SpendCategory::class;

            if ($model::whereKey($ids)->count() !== count(array_unique($ids))) {
                $validator->errors()->add('value', 'One or more selected records do not exist.');
            }
        }];
    }

    /** @return array{field: string, operator: string, value: mixed} */
    public function configuration(): array
    {
        $field = WorkflowRuleField::from($this->validated('field'));
        $operator = WorkflowRuleOperator::from($this->validated('operator'));

        if ($field === WorkflowRuleField::Amount) {
            $value = Money::of($this->validated('value'))->decimal();
        } elseif ($operator === WorkflowRuleOperator::In) {
            $value = $this->referenceIds($operator);
        } else {
            $value = (int) $this->validated('value');
        }

        return ['field' => $field->value, 'operator' => $operator->value, 'value' => $value];
    }

    /** @return list<int> */
    private function referenceIds(WorkflowRuleOperator $operator): array
    {
        if ($operator !== WorkflowRuleOperator::In) {
            return ctype_digit($this->string('value')->toString()) ? [(int) $this->input('value')] : [];
        }

        $values = $this->input('value_ids');

        if (is_array($values) && $values !== []) {
            return array_values(array_unique(array_map('intval', $values)));
        }

        $parts = array_filter(array_map('trim', explode(',', $this->string('value')->toString())), fn (string $value): bool => ctype_digit($value));

        return count($parts) === count(array_filter(explode(',', $this->string('value')->toString()), fn (string $value): bool => trim($value) !== ''))
            ? array_values(array_unique(array_map('intval', $parts)))
            : [];
    }
}
