<?php

declare(strict_types=1);

namespace ON\Data\ORM\ManualProjection;

use ON\Data\Key;
use ON\Data\Query\SelectQuery;

final class ProjectionSourceDeclaration
{
	public function __construct(
		private ManualProjectionBuilder $builder,
		private SelectQuery $source,
	) {
	}

	public function tracked(): SelectQuery
	{
		return $this->builder->trackSource($this->source);
	}

	/**
	 * @param array<string, mixed> $values
	 */
	public function create(array $values = []): SelectQuery
	{
		return $this->builder->createSource($this->source, $values);
	}

	/**
	 * @param array<string, mixed> $values
	 */
	public function existing(Key|array $key, array $values = []): SelectQuery
	{
		return $this->builder->existingSource($this->source, $key, $values);
	}
}
