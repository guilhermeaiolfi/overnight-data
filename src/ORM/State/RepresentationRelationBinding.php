<?php

declare(strict_types=1);

namespace ON\Data\ORM\State;

use ON\Data\ORM\Exception\StateException;

final class RepresentationRelationBinding
{
	public function __construct(
		private string $path,
		private RecordRelationRef $relation,
		private RepresentationRelationCardinality $cardinality,
		private RepresentationBinding $relatedBinding,
		private bool $collectionFullyLoaded = false,
		private bool $skipWhenMissing = false,
	) {
		if ($path === '') {
			throw new StateException('Representation relation binding path cannot be empty.');
		}
	}

	public function getPath(): string
	{
		return $this->path;
	}

	public function getRelation(): RecordRelationRef
	{
		return $this->relation;
	}

	public function withRelation(RecordRelationRef $relation): self
	{
		return new self($this->path, $relation, $this->cardinality, $this->relatedBinding, $this->collectionFullyLoaded, $this->skipWhenMissing);
	}

	public function getRelationName(): string
	{
		return $this->relation->getRelationName();
	}

	public function getCardinality(): RepresentationRelationCardinality
	{
		return $this->cardinality;
	}

	public function getRelatedBinding(): RepresentationBinding
	{
		return $this->relatedBinding;
	}

	public function isCollectionFullyLoaded(): bool
	{
		return $this->collectionFullyLoaded;
	}

	public function isMany(): bool
	{
		return $this->cardinality === RepresentationRelationCardinality::MANY;
	}

	public function isSingle(): bool
	{
		return $this->cardinality === RepresentationRelationCardinality::ONE;
	}

	public function shouldSkipWhenMissing(): bool
	{
		return $this->skipWhenMissing;
	}
}
