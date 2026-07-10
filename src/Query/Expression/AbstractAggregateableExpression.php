<?php

declare(strict_types=1);

namespace ON\Data\Query\Expression;

use ON\Data\Query\ExpressionFactory;
use function ON\Data\Query\x;

abstract class AbstractAggregateableExpression extends AbstractValueExpression
{
	final public function count(): AggregateExpression
	{
		return $this->factory()->count($this);
	}

	final public function countDistinct(): AggregateExpression
	{
		return $this->factory()->countDistinct($this);
	}

	final public function sum(): AggregateExpression
	{
		return $this->factory()->sum($this);
	}

	final public function avg(): AggregateExpression
	{
		return $this->factory()->avg($this);
	}

	final public function min(): AggregateExpression
	{
		return $this->factory()->min($this);
	}

	final public function max(): AggregateExpression
	{
		return $this->factory()->max($this);
	}

	private function factory(): ExpressionFactory
	{
		return x();
	}
}
