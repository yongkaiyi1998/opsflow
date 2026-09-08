<?php

namespace App\Services;

use App\Exceptions\InvalidWorkflowConfigurationException;
use App\Models\WorkflowRule;
use App\Models\WorkflowRuleGroup;
use App\Support\Money;
use App\WorkflowContext;
use App\WorkflowRuleField;
use App\WorkflowRuleOperator;
use InvalidArgumentException;
use OverflowException;

class RuleEvaluator
{
    public function matches(WorkflowRule $rule, WorkflowContext $context): bool
    {
        $field = WorkflowRuleField::tryFrom((string) $rule->getRawOriginal('field'));
        $operator = WorkflowRuleOperator::tryFrom((string) $rule->getRawOriginal('operator'));

        if ($field === null || $operator === null || ! in_array($operator, $field->operators(), true)) {
            throw new InvalidWorkflowConfigurationException('The workflow contains an unsupported rule field or operator.');
        }

        $configuredValue = $this->normalizeConfiguredValue($field, $operator, $rule->value);
        $contextValue = $context->valueFor($field);

        if ($contextValue === null) {
            return false;
        }

        if ($field === WorkflowRuleField::Amount) {
            return $this->compareAmount($contextValue, $configuredValue, $operator);
        }

        return $this->compareIdentifier($contextValue, $configuredValue, $operator);
    }

    public function matchesGroup(WorkflowRuleGroup $group, WorkflowContext $context): bool
    {
        if ($group->is_default || $group->rules->isEmpty()) {
            throw new InvalidWorkflowConfigurationException('Only non-default rule groups with rules may be evaluated.');
        }

        $matches = true;

        foreach ($group->rules->sortBy('id') as $rule) {
            if (! $this->matches($rule, $context)) {
                $matches = false;
            }
        }

        return $matches;
    }

    private function normalizeConfiguredValue(WorkflowRuleField $field, WorkflowRuleOperator $operator, mixed $value): Money|int|array
    {
        if ($field === WorkflowRuleField::Amount) {
            try {
                $amount = Money::of(is_string($value) || is_int($value) ? $value : 'invalid');
            } catch (InvalidArgumentException|OverflowException $exception) {
                throw new InvalidWorkflowConfigurationException('The workflow contains an invalid amount rule value.', previous: $exception);
            }

            if ($amount->compare(0) < 0) {
                throw new InvalidWorkflowConfigurationException('The workflow contains a negative amount rule value.');
            }

            return $amount;
        }

        if ($operator === WorkflowRuleOperator::In) {
            if (! is_array($value) || ! array_is_list($value) || $value === [] || collect($value)->contains(fn (mixed $id): bool => ! is_int($id) || $id < 1)) {
                throw new InvalidWorkflowConfigurationException('The workflow contains an invalid IN rule value.');
            }

            return $value;
        }

        if (! is_int($value) || $value < 1) {
            throw new InvalidWorkflowConfigurationException('The workflow contains an invalid reference rule value.');
        }

        return $value;
    }

    private function compareAmount(Money $actual, Money $configured, WorkflowRuleOperator $operator): bool
    {
        $comparison = $actual->compare($configured);

        return match ($operator) {
            WorkflowRuleOperator::Equal => $comparison === 0,
            WorkflowRuleOperator::NotEqual => $comparison !== 0,
            WorkflowRuleOperator::GreaterThan => $comparison > 0,
            WorkflowRuleOperator::GreaterThanOrEqual => $comparison >= 0,
            WorkflowRuleOperator::LessThan => $comparison < 0,
            WorkflowRuleOperator::LessThanOrEqual => $comparison <= 0,
            default => throw new InvalidWorkflowConfigurationException('The workflow contains an invalid amount operator.'),
        };
    }

    /** @param int|list<int> $configured */
    private function compareIdentifier(int $actual, int|array $configured, WorkflowRuleOperator $operator): bool
    {
        return match ($operator) {
            WorkflowRuleOperator::Equal => $actual === $configured,
            WorkflowRuleOperator::NotEqual => $actual !== $configured,
            WorkflowRuleOperator::In => is_array($configured) && in_array($actual, $configured, true),
            default => throw new InvalidWorkflowConfigurationException('The workflow contains an invalid reference operator.'),
        };
    }
}
