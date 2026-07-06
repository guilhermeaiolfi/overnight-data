<?php

declare(strict_types=1);

namespace ON\Data\ORM\ManualProjection;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Key;

final class ProjectionSourceDeclaration
{
	public function __construct(
		private ManualProjectionBuilder $builder,
		private CollectionInterface $collection,
	) {
	}

	public function tracked(): ManualProjectionRootTarget
	{
		return $this->builder->trackSource($this->collection);
	}

	/**
	 * @param array<string, mixed> $values
	 */
	public function create(array $values = []): ManualProjectionRootTarget
	{
		return $this->builder->createSource($this->collection, $values);
	}

	/**
	 * @param array<string, mixed> $values
	 */
	public function existing(Key|array $key, array $values = []): ManualProjectionRootTarget
	{
		return $this->builder->existingSource($this->collection, $key, $values);
	}
}
