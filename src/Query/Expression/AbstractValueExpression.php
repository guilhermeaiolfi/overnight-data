<?php

declare(strict_types=1);

namespace ON\Data\Query\Expression;

use LogicException;
use ON\Data\Query\ExpressionFactory;
use ON\Data\Query\Sort\Sort;
use function ON\Data\Query\x;

abstract class AbstractValueExpression implements ValueExpressionInterface
{
	public function getSelectionKey(): string
	{
		if (! method_exists($this, 'getPath')) {
			throw new LogicException(sprintf(
				'Expression %s does not expose a stable selection key; alias it before selecting.',
				static::class,
			));
		}

		/** @var list<string> $path */
		$path = $this->getPath();

		return implode('.', $path);
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

	private function factory(): ExpressionFactory
	{
		return x();
	}
}
