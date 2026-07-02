<?php

declare(strict_types=1);

namespace ON\Data\Query\Expression;

use ON\Data\Query\ExpressionFactory;
use ON\Data\Query\QuerySourceInterface;
use ON\Data\Query\SelectQuery;
use function ON\Data\Query\x;

final class StarExpression
{
	public function __construct(
		private readonly QuerySourceInterface $source,
	) {
	}

	public function getSource(): QuerySourceInterface
	{
		return $this->source;
	}

	public function getQuery(): SelectQuery
	{
		return $this->source->getQuery();
	}

	public function getSelectionKey(): string
	{
		return implode('.', [...$this->source->getPath(), '*']);
	}

	public function bindTo(QuerySourceInterface $target, ?QuerySourceInterface $from = null): self
	{
		if ($from !== null && $this->source === $from) {
			return $target->all();
		}

		return $this;
	}

	public function count(): AggregateExpression
	{
		return $this->factory()->count($this);
	}

	private function factory(): ExpressionFactory
	{
		return x();
	}
}
