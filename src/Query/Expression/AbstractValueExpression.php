<?php

declare(strict_types=1);

namespace ON\Data\Query\Expression;

use LogicException;
use ON\Data\Query\Condition\ConditionInterface;
use ON\Data\Query\ExpressionFactory;
use ON\Data\Query\QuerySourceInterface;
use ON\Data\Query\Sort\Sort;
use function ON\Data\Query\x;

abstract class AbstractValueExpression implements ValueExpressionInterface
{
	public function getSelectionKey(): string
	{
		throw new LogicException(sprintf(
			'Expression %s cannot provide a selection key without an alias.',
			static::class,
		));
	}

	public function rebaseFields(QuerySourceInterface $from, QuerySourceInterface $to): self
	{
		return $this;
	}

	final public function as(string $alias): AliasedExpression
	{
		return new AliasedExpression($this, $alias);
	}

	final public function upper(): ValueOperationExpression
	{
		return $this->factory()->upper($this);
	}

	final public function lower(): ValueOperationExpression
	{
		return $this->factory()->lower($this);
	}

	final public function asc(): Sort
	{
		return $this->factory()->asc($this);
	}

	final public function desc(): Sort
	{
		return $this->factory()->desc($this);
	}

	final public function eq(mixed $right): ConditionInterface
	{
		return $this->factory()->eq($this, $right);
	}

	final public function neq(mixed $right): ConditionInterface
	{
		return $this->factory()->neq($this, $right);
	}

	final public function gt(mixed $right): ConditionInterface
	{
		return $this->factory()->gt($this, $right);
	}

	final public function gte(mixed $right): ConditionInterface
	{
		return $this->factory()->gte($this, $right);
	}

	final public function lt(mixed $right): ConditionInterface
	{
		return $this->factory()->lt($this, $right);
	}

	final public function lte(mixed $right): ConditionInterface
	{
		return $this->factory()->lte($this, $right);
	}

	private function factory(): ExpressionFactory
	{
		return x();
	}
}
