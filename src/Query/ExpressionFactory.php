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
use ON\Data\Query\Expression\RawSqlExpression;
use ON\Data\Query\Expression\StarExpression;
use ON\Data\Query\Expression\SubqueryExpression;
use ON\Data\Query\Expression\ValueExpressionInterface;
use ON\Data\Query\Expression\ValueOperation;
use ON\Data\Query\Expression\ValueOperationExpression;
use ON\Data\Query\Sort\Sort;
use ON\Data\Query\Sort\SortDirection;

final class ExpressionFactory
{
	private ?ExpressionFunctionFactory $functions = null;

	public function fn(): ExpressionFunctionFactory
	{
		return $this->functions ??= new ExpressionFunctionFactory();
	}

	public function literal(mixed $value): LiteralExpression
	{
		return new LiteralExpression($value);
	}

	/**
	 * @param list<mixed> $parameters
	 */
	public function rawSql(string $sql, array $parameters = []): RawSqlExpression
	{
		return new RawSqlExpression($sql, array_values($parameters));
	}

	public function eq(ValueExpressionInterface|SelectQuery $left, mixed $right): ConditionInterface
	{
		if ($right === null) {
			return new NullCondition($this->normalizeExpression($left), NullOperator::IS_NULL);
		}

		return new ComparisonCondition($this->normalizeExpression($left), ComparisonOperator::EQ, $this->normalizeOperand($right, 'comparison'));
	}

	public function neq(ValueExpressionInterface|SelectQuery $left, mixed $right): ConditionInterface
	{
		if ($right === null) {
			return new NullCondition($this->normalizeExpression($left), NullOperator::IS_NOT_NULL);
		}

		return new ComparisonCondition($this->normalizeExpression($left), ComparisonOperator::NEQ, $this->normalizeOperand($right, 'comparison'));
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

	public function like(ValueExpressionInterface|SelectQuery $expression, mixed $pattern): ComparisonCondition
	{
		return new ComparisonCondition(
			$this->normalizeExpression($expression),
			ComparisonOperator::LIKE,
			$this->normalizeOrderedOperand($pattern, 'like'),
		);
	}

	public function notLike(ValueExpressionInterface|SelectQuery $expression, mixed $pattern): ComparisonCondition
	{
		return new ComparisonCondition(
			$this->normalizeExpression($expression),
			ComparisonOperator::NOT_LIKE,
			$this->normalizeOrderedOperand($pattern, 'notLike'),
		);
	}

	public function contains(ValueExpressionInterface|SelectQuery $expression, string $value): ComparisonCondition
	{
		return new ComparisonCondition(
			$this->normalizeExpression($expression),
			ComparisonOperator::LIKE,
			new LiteralExpression('%' . $value . '%'),
		);
	}

	public function notContains(ValueExpressionInterface|SelectQuery $expression, string $value): ComparisonCondition
	{
		return new ComparisonCondition(
			$this->normalizeExpression($expression),
			ComparisonOperator::NOT_LIKE,
			new LiteralExpression('%' . $value . '%'),
		);
	}

	public function startsWith(ValueExpressionInterface|SelectQuery $expression, string $value): ComparisonCondition
	{
		return new ComparisonCondition(
			$this->normalizeExpression($expression),
			ComparisonOperator::LIKE,
			new LiteralExpression($value . '%'),
		);
	}

	public function endsWith(ValueExpressionInterface|SelectQuery $expression, string $value): ComparisonCondition
	{
		return new ComparisonCondition(
			$this->normalizeExpression($expression),
			ComparisonOperator::LIKE,
			new LiteralExpression('%' . $value),
		);
	}

	public function count(ValueExpressionInterface|StarExpression $expression): AggregateExpression
	{
		if ($expression instanceof AliasedExpression) {
			throw new InvalidArgumentException('AliasedExpression cannot be used as an aggregate operand.');
		}

		if ($expression instanceof ValueExpressionInterface) {
			$this->assertAggregateInput($expression);
		}

		return new AggregateExpression(AggregateFunction::COUNT, $expression);
	}

	public function countDistinct(ValueExpressionInterface $expression): AggregateExpression
	{
		if ($expression instanceof AliasedExpression) {
			throw new InvalidArgumentException('AliasedExpression cannot be used as an aggregate operand.');
		}

		$this->assertAggregateInput($expression);

		return new AggregateExpression(AggregateFunction::COUNT_DISTINCT, $expression);
	}

	public function sum(ValueExpressionInterface $expression): AggregateExpression
	{
		if ($expression instanceof AliasedExpression) {
			throw new InvalidArgumentException('AliasedExpression cannot be used as an aggregate operand.');
		}

		$this->assertAggregateInput($expression);

		return new AggregateExpression(AggregateFunction::SUM, $expression);
	}

	public function avg(ValueExpressionInterface $expression): AggregateExpression
	{
		if ($expression instanceof AliasedExpression) {
			throw new InvalidArgumentException('AliasedExpression cannot be used as an aggregate operand.');
		}

		$this->assertAggregateInput($expression);

		return new AggregateExpression(AggregateFunction::AVG, $expression);
	}

	public function min(ValueExpressionInterface $expression): AggregateExpression
	{
		if ($expression instanceof AliasedExpression) {
			throw new InvalidArgumentException('AliasedExpression cannot be used as an aggregate operand.');
		}

		$this->assertAggregateInput($expression);

		return new AggregateExpression(AggregateFunction::MIN, $expression);
	}

	public function max(ValueExpressionInterface $expression): AggregateExpression
	{
		if ($expression instanceof AliasedExpression) {
			throw new InvalidArgumentException('AliasedExpression cannot be used as an aggregate operand.');
		}

		$this->assertAggregateInput($expression);

		return new AggregateExpression(AggregateFunction::MAX, $expression);
	}

	public function upper(mixed $expression): ValueOperationExpression
	{
		return new ValueOperationExpression(
			ValueOperation::UPPER,
			[$this->normalizeOperand($expression, 'value operation')],
		);
	}

	public function lower(mixed $expression): ValueOperationExpression
	{
		return new ValueOperationExpression(
			ValueOperation::LOWER,
			[$this->normalizeOperand($expression, 'value operation')],
		);
	}

	public function concat(mixed ...$arguments): ValueOperationExpression
	{
		return new ValueOperationExpression(ValueOperation::CONCAT, $this->normalizeOperationArguments($arguments));
	}

	public function coalesce(mixed ...$arguments): ValueOperationExpression
	{
		return new ValueOperationExpression(ValueOperation::COALESCE, $this->normalizeOperationArguments($arguments));
	}

	public function add(mixed ...$arguments): ValueOperationExpression
	{
		return new ValueOperationExpression(ValueOperation::ADD, $this->normalizeOperationArguments($arguments));
	}

	public function asc(ValueExpressionInterface|SelectQuery $expression): Sort
	{
		return new Sort($this->normalizeExpression($expression), SortDirection::ASC);
	}

	public function desc(ValueExpressionInterface|SelectQuery $expression): Sort
	{
		return new Sort($this->normalizeExpression($expression), SortDirection::DESC);
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
			throw new InvalidArgumentException(sprintf('ExpressionFactory::%s() does not accept null.', $method));
		}

		return $this->normalizeOperand($operand, 'comparison');
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

	private function normalizeOperand(mixed $operand, string $context): ValueExpressionInterface
	{
		if ($operand instanceof AliasedExpression) {
			throw new InvalidArgumentException(sprintf('AliasedExpression cannot be used as a %s operand.', $context));
		}

		if ($operand instanceof StarExpression) {
			throw new InvalidArgumentException(sprintf('StarExpression cannot be used as a %s operand.', $context));
		}

		if ($operand instanceof ConditionInterface) {
			throw new InvalidArgumentException(sprintf('ConditionInterface cannot be used as a %s operand.', $context));
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

			if ($value instanceof SelectQuery || $value instanceof SubqueryExpression) {
				throw new InvalidArgumentException('ExpressionFactory::in() does not accept subqueries inside literal lists.');
			}

			$normalized[] = $this->normalizeOperand($value, 'IN set');
		}

		return $normalized;
	}

	/**
	 * @param list<mixed> $arguments
	 * @return list<ValueExpressionInterface>
	 */
	private function normalizeOperationArguments(array $arguments): array
	{
		return array_map(
			fn (mixed $argument): ValueExpressionInterface => $this->normalizeOperand($argument, 'value operation'),
			$arguments,
		);
	}

	private function assertAggregateInput(ValueExpressionInterface $expression): void
	{
		if ($this->containsAggregateAtCurrentLevel($expression)) {
			throw new InvalidArgumentException('Aggregate expressions cannot be aggregated directly.');
		}
	}

	private function containsAggregateAtCurrentLevel(ValueExpressionInterface $expression): bool
	{
		if ($expression instanceof AggregateExpression) {
			return true;
		}

		if (! $expression instanceof ValueOperationExpression) {
			return false;
		}

		foreach ($expression->getArguments() as $argument) {
			if ($this->containsAggregateAtCurrentLevel($argument)) {
				return true;
			}
		}

		return false;
	}
}
