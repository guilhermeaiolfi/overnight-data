<?php

declare(strict_types=1);

namespace ON\Data\Query\Expression;

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
}
