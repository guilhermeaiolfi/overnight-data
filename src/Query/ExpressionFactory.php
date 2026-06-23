<?php

declare(strict_types=1);

namespace ON\Data\Query;

use InvalidArgumentException;
use ON\Data\Query\Condition\ComparisonCondition;
use ON\Data\Query\Condition\ComparisonOperator;
use ON\Data\Query\Condition\ConditionInterface;
use ON\Data\Query\Condition\LogicalCondition;
use ON\Data\Query\Condition\LogicalOperator;
use ON\Data\Query\Condition\NotCondition;
use ON\Data\Query\Condition\NullCondition;
use ON\Data\Query\Condition\NullOperator;
use ON\Data\Query\Expression\AliasedExpression;
use ON\Data\Query\Expression\LiteralExpression;
use ON\Data\Query\Expression\ValueExpressionInterface;

final class ExpressionFactory
{
	public function literal(mixed $value): LiteralExpression
	{
		return new LiteralExpression($value);
	}

	public function eq(ValueExpressionInterface $left, mixed $right): ConditionInterface
	{
		if ($right === null) {
			return new NullCondition($left, NullOperator::IS_NULL);
		}

		return new ComparisonCondition($left, ComparisonOperator::EQ, $this->normalizeRightOperand($right));
	}

	public function neq(ValueExpressionInterface $left, mixed $right): ConditionInterface
	{
		if ($right === null) {
			return new NullCondition($left, NullOperator::IS_NOT_NULL);
		}

		return new ComparisonCondition($left, ComparisonOperator::NEQ, $this->normalizeRightOperand($right));
	}

	public function gt(ValueExpressionInterface $left, mixed $right): ComparisonCondition
	{
		return new ComparisonCondition($left, ComparisonOperator::GT, $this->normalizeOrderedRightOperand($right, 'gt'));
	}

	public function gte(ValueExpressionInterface $left, mixed $right): ComparisonCondition
	{
		return new ComparisonCondition($left, ComparisonOperator::GTE, $this->normalizeOrderedRightOperand($right, 'gte'));
	}

	public function lt(ValueExpressionInterface $left, mixed $right): ComparisonCondition
	{
		return new ComparisonCondition($left, ComparisonOperator::LT, $this->normalizeOrderedRightOperand($right, 'lt'));
	}

	public function lte(ValueExpressionInterface $left, mixed $right): ComparisonCondition
	{
		return new ComparisonCondition($left, ComparisonOperator::LTE, $this->normalizeOrderedRightOperand($right, 'lte'));
	}

	public function and(ConditionInterface ...$conditions): LogicalCondition
	{
		return new LogicalCondition(LogicalOperator::AND, $conditions);
	}

	public function or(ConditionInterface ...$conditions): LogicalCondition
	{
		return new LogicalCondition(LogicalOperator::OR, $conditions);
	}

	public function not(ConditionInterface $condition): NotCondition
	{
		return new NotCondition($condition);
	}

	public function isNull(ValueExpressionInterface $expression): NullCondition
	{
		return new NullCondition($expression, NullOperator::IS_NULL);
	}

	public function isNotNull(ValueExpressionInterface $expression): NullCondition
	{
		return new NullCondition($expression, NullOperator::IS_NOT_NULL);
	}

	private function normalizeOrderedRightOperand(mixed $right, string $method): ValueExpressionInterface
	{
		if ($right === null) {
			throw new InvalidArgumentException(sprintf("ExpressionFactory::%s() does not accept null.", $method));
		}

		return $this->normalizeRightOperand($right);
	}

	private function normalizeRightOperand(mixed $right): ValueExpressionInterface
	{
		if ($right instanceof AliasedExpression) {
			throw new InvalidArgumentException('AliasedExpression cannot be used as a comparison operand.');
		}

		if ($right instanceof ValueExpressionInterface) {
			return $right;
		}

		return new LiteralExpression($right);
	}
}
