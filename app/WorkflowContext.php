<?php

namespace App;

use App\Support\Money;
use InvalidArgumentException;

final readonly class WorkflowContext
{
    public string $currency;

    public function __construct(
        public WorkflowModuleType $moduleType,
        public int $requesterId,
        public ?int $departmentId,
        public ?int $categoryId,
        public Money $amount,
        string $currency,
    ) {
        if ($requesterId < 1 || ($departmentId !== null && $departmentId < 1) || ($categoryId !== null && $categoryId < 1)) {
            throw new InvalidArgumentException('Workflow identifiers must be positive integers.');
        }

        if ($amount->compare(0) < 0) {
            throw new InvalidArgumentException('Workflow amount cannot be negative.');
        }

        $normalizedCurrency = strtoupper(trim($currency));

        if (preg_match('/^[A-Z]{3}$/', $normalizedCurrency) !== 1) {
            throw new InvalidArgumentException('Currency must be a three-letter code.');
        }

        $this->currency = $normalizedCurrency;
    }

    public static function fromValues(
        WorkflowModuleType|string $moduleType,
        int $requesterId,
        ?int $departmentId,
        ?int $categoryId,
        string|int $amount,
        string $currency,
    ): self {
        return new self(
            moduleType: is_string($moduleType) ? WorkflowModuleType::from($moduleType) : $moduleType,
            requesterId: $requesterId,
            departmentId: $departmentId,
            categoryId: $categoryId,
            amount: Money::of($amount),
            currency: $currency,
        );
    }

    public function valueFor(WorkflowRuleField $field): Money|int|null
    {
        return match ($field) {
            WorkflowRuleField::Amount => $this->amount,
            WorkflowRuleField::Department => $this->departmentId,
            WorkflowRuleField::Category => $this->categoryId,
        };
    }
}
