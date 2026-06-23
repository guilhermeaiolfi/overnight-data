<?php

declare(strict_types=1);

namespace ON\Data\Query\Expression;

abstract class AbstractValueExpression implements ValueExpressionInterface
{
	final public function as(string $alias): AliasedExpression
	{
		return new AliasedExpression($this, $alias);
	}
}
