<?php

declare(strict_types=1);

namespace ON\Data\Query\Expression;

use InvalidArgumentException;
use ON\Data\Query\QuerySourceInterface;

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

	public function bindTo(QuerySourceInterface $target, ?QuerySourceInterface $from = null): self
	{
		$expression = $this->expression->bindTo($target, from: $from);

		if ($expression === $this->expression) {
			return $this;
		}

		return new self($expression, $this->alias);
	}

	public function rebaseFields(QuerySourceInterface $from, QuerySourceInterface $to): self
	{
		return $this->bindTo($to, from: $from);
	}
}
