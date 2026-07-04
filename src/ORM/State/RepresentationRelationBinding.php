<?php

declare(strict_types=1);

namespace ON\Data\ORM\State;

use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\Relation\RelationCollectionState;

final class RepresentationRelationBinding
{
	public function __construct(
		private string $path,
		private string $relationName,
		private RepresentationRelationCardinality $cardinality,
		private RepresentationBinding $relatedBinding,
		private RelationCollectionState $collectionState = RelationCollectionState::UNLOADED,
	) {
		if ($path === '') {
			throw new StateException('Representation relation binding path cannot be empty.');
		}

		if ($relationName === '') {
			throw new StateException('Representation relation binding relation name cannot be empty.');
		}
	}

	public function getPath(): string
	{
		return $this->path;
	}

	public function getRelationName(): string
	{
		return $this->relationName;
	}

	public function getCardinality(): RepresentationRelationCardinality
	{
		return $this->cardinality;
	}

	public function getRelatedBinding(): RepresentationBinding
	{
		return $this->relatedBinding;
	}

	public function getCollectionState(): RelationCollectionState
	{
		return $this->collectionState;
	}

	public function isMany(): bool
	{
		return $this->cardinality === RepresentationRelationCardinality::MANY;
	}

	public function isSingle(): bool
	{
		return $this->cardinality === RepresentationRelationCardinality::ONE;
	}
}
