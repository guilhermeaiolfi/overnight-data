<?php

declare(strict_types=1);

namespace ON\Data\Query;

use InvalidArgumentException;
use ON\Data\Query\Condition\ComparisonCondition;
use ON\Data\Query\Condition\ComparisonOperator;
use ON\Data\Query\Condition\ConditionInterface;
use ON\Data\Query\Condition\ExistsCondition;
use ON\Data\Query\Condition\InCondition;
use ON\Data\Query\Condition\LogicalCondition;
use ON\Data\Query\Condition\LogicalOperator;
use ON\Data\Query\Condition\NotCondition;
use ON\Data\Query\Condition\NullCondition;
use ON\Data\Query\Condition\NullOperator;
use ON\Data\Query\Expression\AggregateExpression;
use ON\Data\Query\Expression\AggregateFunction;
use ON\Data\Query\Expression\AliasedExpression;
use ON\Data\Query\Expression\LiteralExpression;
use ON\Data\Query\Expression\StarExpression;
use ON\Data\Query\Expression\SubqueryExpression;
use ON\Data\Query\Expression\ValueExpressionInterface;

final class ExpressionFactory
{
	public function literal(mixed $value): LiteralExpression
	{
		return new LiteralExpression($value);
	}

	public function eq(ValueExpressionInterface|SelectQuery $left, mixed $right): ConditionInterface
	{
		if ($right === null) {
			return new NullCondition($this->normalizeExpression($left), NullOperator::IS_NULL);
		}

		return new ComparisonCondition($this->normalizeExpression($left), ComparisonOperator::EQ, $this->normalizeOperand($right));
	}

	public function neq(ValueExpressionInterface|SelectQuery $left, mixed $right): ConditionInterface
	{
		if ($right === null) {
			return new NullCondition($this->normalizeExpression($left), NullOperator::IS_NOT_NULL);
		}

		return new ComparisonCondition($this->normalizeExpression($left), ComparisonOperator::NEQ, $this->normalizeOperand($right));
	}

	public function gt(ValueExpressionInterface|SelectQuery $left, mixed $right): ComparisonCondition
	{
		return new ComparisonCondition($this->normalizeExpression($left), ComparisonOperator::GT, $this->normalizeOrderedOperand($right, 'gt'));
	}

	public function gte(ValueExpressionInterface|SelectQuery $left, mixed $right): ComparisonCondition
	{
		return new ComparisonCondition($this->normalizeExpression($left), ComparisonOperator::GTE, $this->normalizeOrderedOperand($right, 'gte'));
	}

	public function lt(ValueExpressionInterface|SelectQuery $left, mixed $right): ComparisonCondition
	{
		return new ComparisonCondition($this->normalizeExpression($left), ComparisonOperator::LT, $this->normalizeOrderedOperand($right, 'lt'));
	}

	public function lte(ValueExpressionInterface|SelectQuery $left, mixed $right): ComparisonCondition
	{
		return new ComparisonCondition($this->normalizeExpression($left), ComparisonOperator::LTE, $this->normalizeOrderedOperand($right, 'lte'));
	}

	public function count(ValueExpressionInterface|StarExpression $expression): AggregateExpression
	{
		if ($expression instanceof AliasedExpression) {
			throw new InvalidArgumentException('AliasedExpression cannot be used as an aggregate operand.');
		}

		return new AggregateExpression(AggregateFunction::COUNT, $expression);
	}

	public function countDistinct(ValueExpressionInterface $expression): AggregateExpression
	{
		if ($expression instanceof AliasedExpression) {
			throw new InvalidArgumentException('AliasedExpression cannot be used as an aggregate operand.');
		}

		return new AggregateExpression(AggregateFunction::COUNT_DISTINCT, $expression);
	}

	public function sum(ValueExpressionInterface $expression): AggregateExpression
	{
		if ($expression instanceof AliasedExpression) {
			throw new InvalidArgumentException('AliasedExpression cannot be used as an aggregate operand.');
		}

		return new AggregateExpression(AggregateFunction::SUM, $expression);
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

	public function exists(SelectQuery $query): ExistsCondition
	{
		return new ExistsCondition($query);
	}

	public function notExists(SelectQuery $query): ExistsCondition
	{
		return new ExistsCondition($query, true);
	}

	/**
	 * @param non-empty-list<mixed>|SelectQuery $set
	 */
	public function in(ValueExpressionInterface|SelectQuery $expression, array|SelectQuery $set): InCondition
	{
		return new InCondition(
			$this->normalizeExpression($expression),
			$this->normalizeInSet($set),
		);
	}

	/**
	 * @param non-empty-list<mixed>|SelectQuery $set
	 */
	public function notIn(ValueExpressionInterface|SelectQuery $expression, array|SelectQuery $set): InCondition
	{
		return new InCondition(
			$this->normalizeExpression($expression),
			$this->normalizeInSet($set),
			true,
		);
	}

	private function normalizeOrderedOperand(mixed $operand, string $method): ValueExpressionInterface
	{
		if ($operand === null) {
			throw new InvalidArgumentException(sprintf("ExpressionFactory::%s() does not accept null.", $method));
		}

		return $this->normalizeOperand($operand);
	}

	private function normalizeExpression(ValueExpressionInterface|SelectQuery $expression): ValueExpressionInterface
	{
		if ($expression instanceof AliasedExpression) {
			throw new InvalidArgumentException('AliasedExpression cannot be used as a comparison operand.');
		}

		if ($expression instanceof SelectQuery) {
			return new SubqueryExpression($expression);
		}

		return $expression;
	}

	private function normalizeOperand(mixed $operand): ValueExpressionInterface
	{
		if ($operand instanceof AliasedExpression) {
			throw new InvalidArgumentException('AliasedExpression cannot be used as a comparison operand.');
		}

		if ($operand instanceof SelectQuery) {
			return new SubqueryExpression($operand);
		}

		if ($operand instanceof ValueExpressionInterface) {
			return $operand;
		}

		return new LiteralExpression($operand);
	}

	/**
	 * @param non-empty-list<mixed>|SelectQuery $set
	 * @return non-empty-list<ValueExpressionInterface>|SubqueryExpression
	 */
	private function normalizeInSet(array|SelectQuery $set): array|SubqueryExpression
	{
		if ($set instanceof SelectQuery) {
			return new SubqueryExpression($set);
		}

		if ($set === []) {
			throw new InvalidArgumentException('ExpressionFactory::in() requires a non-empty set.');
		}

		$normalized = [];

		foreach ($set as $value) {
			if ($value === null) {
				throw new InvalidArgumentException('ExpressionFactory::in() does not accept null inside literal lists.');
			}

			if ($value instanceof StarExpression) {
				throw new InvalidArgumentException('StarExpression cannot be used in an IN set.');
			}

			$normalized[] = $this->normalizeOperand($value);
		}

		return $normalized;
	}
}
