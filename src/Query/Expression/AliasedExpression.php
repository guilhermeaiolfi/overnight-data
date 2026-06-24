<?php

declare(strict_types=1);

namespace ON\Data\Query\Expression;

use InvalidArgumentException;

final class AliasedExpression
{
	private string $alias;

	public function __construct(
		private readonly ValueExpressionInterface $expression,
		string $alias,
	) {
		$this->alias = trim($alias);

		if ($this->alias === '') {
			throw new InvalidArgumentException('Query expression aliases cannot be empty.');
		}
	}

	public function getExpression(): ValueExpressionInterface
	{
		return $this->expression;
	}

	public function getAlias(): string
	{
		return $this->alias;
	}
}
