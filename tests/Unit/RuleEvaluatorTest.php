<?php

namespace Tests\Unit;

use App\Exceptions\InvalidWorkflowConfigurationException;
use App\Models\WorkflowRule;
use App\Models\WorkflowRuleGroup;
use App\Services\RuleEvaluator;
use App\WorkflowContext;
use App\WorkflowModuleType;
use App\WorkflowRuleField;
use App\WorkflowRuleOperator;
use Illuminate\Database\Eloquent\Collection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RuleEvaluatorTest extends TestCase
{
    private RuleEvaluator $evaluator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->evaluator = new RuleEvaluator;
    }

    #[DataProvider('amountComparisonProvider')]
    public function test_amount_operators_are_evaluated_with_decimal_precision(
        string $actual,
        WorkflowRuleOperator $operator,
        string $configured,
        bool $expected,
    ): void {
        $rule = $this->rule(WorkflowRuleField::Amount, $operator, $configured);

        $this->assertSame($expected, $this->evaluator->matches($rule, $this->context(amount: $actual)));
    }

    /** @return iterable<string, array{string, WorkflowRuleOperator, string, bool}> */
    public static function amountComparisonProvider(): iterable
    {
        yield 'equal at exact cents' => ['1000.00', WorkflowRuleOperator::Equal, '1000.00', true];
        yield 'equal after decimal rounding' => ['1000.004', WorkflowRuleOperator::Equal, '1000.00', true];
        yield 'not equal one cent apart' => ['1000.01', WorkflowRuleOperator::NotEqual, '1000.00', true];
        yield 'greater than one cent apart' => ['1000.01', WorkflowRuleOperator::GreaterThan, '1000.00', true];
        yield 'greater than rejects equality' => ['1000.00', WorkflowRuleOperator::GreaterThan, '1000.00', false];
        yield 'greater than or equal accepts equality' => ['1000.00', WorkflowRuleOperator::GreaterThanOrEqual, '1000.00', true];
        yield 'less than one cent apart' => ['999.99', WorkflowRuleOperator::LessThan, '1000.00', true];
        yield 'less than rejects equality' => ['1000.00', WorkflowRuleOperator::LessThan, '1000.00', false];
        yield 'less than or equal accepts equality' => ['1000.00', WorkflowRuleOperator::LessThanOrEqual, '1000.00', true];
        yield 'rounding half up changes comparison' => ['1000.005', WorkflowRuleOperator::GreaterThan, '1000.00', true];
    }

    public function test_identifier_equality_inequality_and_in_are_strict_and_deterministic(): void
    {
        $context = $this->context(departmentId: 12, categoryId: 34);

        $this->assertTrue($this->evaluator->matches($this->rule(WorkflowRuleField::Department, WorkflowRuleOperator::Equal, 12), $context));
        $this->assertTrue($this->evaluator->matches($this->rule(WorkflowRuleField::Department, WorkflowRuleOperator::NotEqual, 13), $context));
        $this->assertTrue($this->evaluator->matches($this->rule(WorkflowRuleField::Category, WorkflowRuleOperator::In, [7, 34, 51]), $context));
        $this->assertFalse($this->evaluator->matches($this->rule(WorkflowRuleField::Category, WorkflowRuleOperator::In, [7, 51]), $context));
    }

    public function test_null_context_values_do_not_match_valid_reference_rules(): void
    {
        $context = $this->context(departmentId: null, categoryId: null);

        $this->assertFalse($this->evaluator->matches($this->rule(WorkflowRuleField::Department, WorkflowRuleOperator::NotEqual, 12), $context));
        $this->assertFalse($this->evaluator->matches($this->rule(WorkflowRuleField::Category, WorkflowRuleOperator::In, [34]), $context));
    }

    public function test_all_rules_within_a_group_must_match(): void
    {
        $matching = $this->group([
            $this->rule(WorkflowRuleField::Amount, WorkflowRuleOperator::GreaterThanOrEqual, '1000.00', 2),
            $this->rule(WorkflowRuleField::Department, WorkflowRuleOperator::Equal, 12, 1),
        ]);
        $notMatching = $this->group([
            $this->rule(WorkflowRuleField::Amount, WorkflowRuleOperator::GreaterThanOrEqual, '1000.00', 1),
            $this->rule(WorkflowRuleField::Category, WorkflowRuleOperator::Equal, 99, 2),
        ]);

        $this->assertTrue($this->evaluator->matchesGroup($matching, $this->context()));
        $this->assertFalse($this->evaluator->matchesGroup($notMatching, $this->context()));
    }

    public function test_invalid_configuration_fails_closed_even_after_an_earlier_rule_does_not_match(): void
    {
        $group = $this->group([
            $this->rule(WorkflowRuleField::Department, WorkflowRuleOperator::Equal, 99, 1),
            $this->rawRule('amount', 'IN', ['1000.00'], 2),
        ]);

        $this->expectException(InvalidWorkflowConfigurationException::class);

        $this->evaluator->matchesGroup($group, $this->context());
    }

    #[DataProvider('invalidRuleProvider')]
    public function test_invalid_field_operator_and_values_fail_closed(WorkflowRule $rule): void
    {
        $this->expectException(InvalidWorkflowConfigurationException::class);

        $this->evaluator->matches($rule, $this->context());
    }

    /** @return iterable<string, array{WorkflowRule}> */
    public static function invalidRuleProvider(): iterable
    {
        yield 'unknown field' => [self::makeRawRule('requester_id', '=', 1)];
        yield 'unknown operator' => [self::makeRawRule('amount', 'LIKE', '1000.00')];
        yield 'incompatible amount operator' => [self::makeRawRule('amount', 'IN', ['1000.00'])];
        yield 'incompatible reference operator' => [self::makeRawRule('department_id', '>', 1)];
        yield 'invalid amount value' => [self::makeRawRule('amount', '>', '1e3')];
        yield 'invalid scalar reference' => [self::makeRawRule('department_id', '=', '12')];
        yield 'empty in value' => [self::makeRawRule('category_id', 'IN', [])];
        yield 'non-integer in value' => [self::makeRawRule('category_id', 'IN', [1, '2'])];
    }

    public function test_workflow_context_normalizes_currency_and_amount(): void
    {
        $context = WorkflowContext::fromValues('PURCHASE_REQUEST', 1, null, null, '12.345', ' myr ');

        $this->assertSame(WorkflowModuleType::PurchaseRequest, $context->moduleType);
        $this->assertSame('12.35', $context->amount->decimal());
        $this->assertSame('MYR', $context->currency);
    }

    private function context(
        string $amount = '1000.00',
        ?int $departmentId = 12,
        ?int $categoryId = 34,
    ): WorkflowContext {
        return WorkflowContext::fromValues(
            WorkflowModuleType::PurchaseRequest,
            1,
            $departmentId,
            $categoryId,
            $amount,
            'MYR',
        );
    }

    private function rule(
        WorkflowRuleField $field,
        WorkflowRuleOperator $operator,
        mixed $value,
        int $id = 1,
    ): WorkflowRule {
        return $this->rawRule($field->value, $operator->value, $value, $id);
    }

    private function rawRule(string $field, string $operator, mixed $value, int $id = 1): WorkflowRule
    {
        return self::makeRawRule($field, $operator, $value, $id);
    }

    private static function makeRawRule(string $field, string $operator, mixed $value, int $id = 1): WorkflowRule
    {
        $rule = new WorkflowRule;
        $rule->setRawAttributes([
            'id' => $id,
            'field' => $field,
            'operator' => $operator,
            'value' => json_encode($value, JSON_THROW_ON_ERROR),
        ], true);

        return $rule;
    }

    /** @param list<WorkflowRule> $rules */
    private function group(array $rules): WorkflowRuleGroup
    {
        $group = new WorkflowRuleGroup(['is_default' => false]);
        $group->setRelation('rules', new Collection($rules));

        return $group;
    }
}
