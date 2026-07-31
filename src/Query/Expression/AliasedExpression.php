<?php

declare(strict_types=1);

namespace ON\Data\Query\Expression;

use InvalidArgumentException;
use ON\Data\Query\SourceMap;

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

	public function getSelectionKey(): string
	{
		return $this->alias;
	}

	public function rebind(SourceMap $sources): self
	{
		$expression = $this->expression->rebind($sources);

		if ($expression === $this->expression) {
			return $this;
		}

		return new self($expression, $this->alias);
	}
}
