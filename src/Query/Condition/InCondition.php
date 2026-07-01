<?php

declare(strict_types=1);

namespace ON\Data\Query\Condition;

use InvalidArgumentException;
use ON\Data\Query\Expression\AggregateExpression;
use ON\Data\Query\Expression\SubqueryExpression;
use ON\Data\Query\Expression\ValueExpressionInterface;
use ON\Data\Query\QuerySourceInterface;

final class InCondition implements ConditionInterface
{
	/**
	 * @param non-empty-list<ValueExpressionInterface>|SubqueryExpression $set
	 */
	public function __construct(
		private readonly ValueExpressionInterface $expression,
		private readonly array|SubqueryExpression $set,
		private readonly bool $negated = false,
	) {
		if (! is_array($this->set)) {
			return;
		}

		if ($this->set === []) {
			throw new InvalidArgumentException('InCondition requires a non-empty set.');
		}

		foreach ($this->set as $item) {
			if (! $item instanceof ValueExpressionInterface) {
				throw new InvalidArgumentException('IN literal lists must contain only value expressions.');
			}

			if ($item instanceof SubqueryExpression) {
				throw new InvalidArgumentException('IN literal lists cannot contain subqueries.');
			}

			if ($item instanceof AggregateExpression) {
				throw new InvalidArgumentException('IN literal lists cannot contain aggregate expressions.');
			}
		}
	}

	public function getExpression(): ValueExpressionInterface
	{
		return $this->expression;
	}

	/**
	 * @return non-empty-list<ValueExpressionInterface>|SubqueryExpression
	 */
	public function getSet(): array|SubqueryExpression
	{
		return $this->set;
	}

	public function isNegated(): bool
	{
		return $this->negated;
	}

	public function rebaseFields(QuerySourceInterface $from, QuerySourceInterface $to): self
	{
		$expression = $this->expression->rebaseFields($from, $to);
		$set = $this->set;
		$changed = $expression !== $this->expression;

		if (is_array($set)) {
			$rebasedSet = [];

			foreach ($set as $item) {
				$rebased = $item->rebaseFields($from, $to);
				$changed = $changed || $rebased !== $item;
				$rebasedSet[] = $rebased;
			}

			$set = $rebasedSet;
		}

		if (! $changed) {
			return $this;
		}

		return new self($expression, $set, $this->negated);
	}
}
