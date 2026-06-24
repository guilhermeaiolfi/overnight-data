<?php

declare(strict_types=1);

namespace ON\Data\Query\Expression;

use ON\Data\Query\ExpressionFactory;
use ON\Data\Query\Sort\Sort;
use function ON\Data\Query\x;

abstract class AbstractValueExpression implements ValueExpressionInterface
{
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

	private function factory(): ExpressionFactory
	{
		return x();
	}
}
