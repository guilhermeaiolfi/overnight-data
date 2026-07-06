<?php

declare(strict_types=1);

namespace ON\Data\ORM\ManualProjection;

use ON\Data\Key;
use ON\Data\ORM\State\RecordState;
use ON\Data\ORM\State\RepresentationBinding;
use ON\Data\ORM\State\RepresentationRelationCardinality;

final class ProjectionPathDeclaration
{
	public function __construct(
		private ManualProjectionBuilder $builder,
		private object $ownerObject,
		private RecordState $owner,
		private string $path,
		private string $relationName,
		private RepresentationRelationCardinality $cardinality,
		private RepresentationBinding $relatedBinding,
	) {
	}

	/**
	 * @param array<string, mixed> $values
	 */
	public function create(array $values = []): ProjectionPathSource
	{
		return $this->builder->createPathSource(
			$this->owner,
			$this->ownerObject,
			$this->path,
			$this->relationName,
			$this->cardinality,
			$this->relatedBinding,
			$values
		);
	}

	/**
	 * @param array<string, mixed> $values
	 */
	public function existing(Key|array $key, array $values = []): ProjectionPathSource
	{
		return $this->builder->existingPathSource(
			$this->owner,
			$this->ownerObject,
			$this->path,
			$this->relationName,
			$this->cardinality,
			$this->relatedBinding,
			$key,
			$values
		);
	}

	public function tracked(?object $object = null): ProjectionPathSource
	{
		return $this->builder->trackedPathSource(
			$this->owner,
			$this->ownerObject,
			$this->path,
			$this->relationName,
			$this->cardinality,
			$this->relatedBinding,
			$object
		);
	}
}
