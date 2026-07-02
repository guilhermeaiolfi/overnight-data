<?php

declare(strict_types=1);

namespace ON\Data\Query\Expression;

use ON\Data\Query\QuerySourceInterface;
use ON\Data\Query\Sort\Sort;

final class WindowSpec
{
	/**
	 * @param list<ValueExpressionInterface> $partitionBy
	 * @param list<Sort> $orderBy
	 */
	public function __construct(
		private readonly array $partitionBy = [],
		private readonly array $orderBy = [],
	) {
	}

	/**
	 * @return list<ValueExpressionInterface>
	 */
	public function getPartitionBy(): array
	{
		return $this->partitionBy;
	}

	/**
	 * @return list<Sort>
	 */
	public function getOrderings(): array
	{
		return $this->orderBy;
	}

	public function bindTo(QuerySourceInterface $target, ?QuerySourceInterface $from = null): self
	{
		$changed = false;
		$partitionBy = [];

		foreach ($this->partitionBy as $partition) {
			$bound = $partition->bindTo($target, from: $from);
			$changed = $changed || $bound !== $partition;
			$partitionBy[] = $bound;
		}

		$orderBy = [];

		foreach ($this->orderBy as $sort) {
			$bound = $sort->bindTo($target, from: $from);
			$changed = $changed || $bound !== $sort;
			$orderBy[] = $bound;
		}

		if (! $changed) {
			return $this;
		}

		return new self($partitionBy, $orderBy);
	}
}
