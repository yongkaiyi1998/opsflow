<?php

namespace App;

enum WorkflowRuleField: string
{
    case Amount = 'amount';
    case Department = 'department_id';
    case Category = 'category_id';

    /** @return list<WorkflowRuleOperator> */
    public function operators(): array
    {
        return match ($this) {
            self::Amount => [WorkflowRuleOperator::Equal, WorkflowRuleOperator::NotEqual, WorkflowRuleOperator::GreaterThan, WorkflowRuleOperator::GreaterThanOrEqual, WorkflowRuleOperator::LessThan, WorkflowRuleOperator::LessThanOrEqual],
            self::Department, self::Category => [WorkflowRuleOperator::Equal, WorkflowRuleOperator::NotEqual, WorkflowRuleOperator::In],
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Amount => 'Amount',
            self::Department => 'Department',
            self::Category => 'Spend category',
        };
    }
}
