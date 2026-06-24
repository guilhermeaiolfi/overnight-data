<?php

declare(strict_types=1);

namespace ON\Data\Query\Expression;

use ON\Data\Query\ExpressionFactory;
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

	private function factory(): ExpressionFactory
	{
		return x();
	}
}
