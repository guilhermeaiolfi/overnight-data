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
	public function create(array $values = []): ManualProjectionTarget
	{
		return $this->builder->createPathTarget(
			$this->owner,
			$this->ownerObject,
			$this->relationName,
			$this->cardinality,
			$this->relatedBinding,
			$values
		);
	}

	/**
	 * @param array<string, mixed> $values
	 */
	public function existing(Key|array $key, array $values = []): ManualProjectionTarget
	{
		return $this->builder->existingPathTarget(
			$this->owner,
			$this->ownerObject,
			$this->relationName,
			$this->cardinality,
			$this->relatedBinding,
			$key,
			$values
		);
	}

	public function tracked(?object $object = null): ManualProjectionTarget
	{
		return $this->builder->trackedPathTarget(
			$this->owner,
			$this->ownerObject,
			$this->relationName,
			$this->cardinality,
			$this->relatedBinding,
			$object
		);
	}
}
