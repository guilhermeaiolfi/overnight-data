<?php

declare(strict_types=1);

namespace ON\Data\Database\Cycle;

use ON\Data\Query\QuerySourceInterface;
use ON\Data\Query\Sort\Sort;

/**
 * @internal
 */
final class CyclePartitionedLimit
{
	/**
	 * @param non-empty-list<string> $partitionFields
	 * @param non-empty-list<Sort> $orderBy
	 */
	public function __construct(
		private readonly QuerySourceInterface $source,
		private readonly array $partitionFields,
		private readonly array $orderBy,
		private readonly int $limit,
		private readonly int $offset,
		private readonly string $rowNumberAlias,
	) {
	}

	public function source(): QuerySourceInterface
	{
		return $this->source;
	}

	/**
	 * @return non-empty-list<string>
	 */
	public function partitionFields(): array
	{
		return $this->partitionFields;
	}

	/**
	 * @return non-empty-list<Sort>
	 */
	public function orderBy(): array
	{
		return $this->orderBy;
	}

	public function limit(): int
	{
		return $this->limit;
	}

	public function offset(): int
	{
		return $this->offset;
	}

	public function rowNumberAlias(): string
	{
		return $this->rowNumberAlias;
	}
}
