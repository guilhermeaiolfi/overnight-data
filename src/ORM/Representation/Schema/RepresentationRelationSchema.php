<?php

declare(strict_types=1);

namespace ON\Data\ORM\Representation\Schema;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Relation\RelationCardinality;
use ON\Data\Definition\Relation\RelationInterface;
use ON\Data\ORM\Exception\StateException;
final class RepresentationRelationSchema
{
	public function __construct(
		private string $path,
		private CollectionInterface $ownerCollection,
		private string $relationName,
		private RepresentationSchema $relatedSchema,
		private bool $skipWhenMissing = false,
	) {
		if ($path === '') {
			throw new StateException('Representation relation schema path cannot be empty.');
		}

		if ($relationName === '') {
			throw new StateException('Representation relation schema relation name cannot be empty.');
		}
	}

	public function getPath(): string
	{
		return $this->path;
	}

	public function getOwnerCollection(): CollectionInterface
	{
		return $this->ownerCollection;
	}

	public function getOwnerCollectionName(): string
	{
		return $this->ownerCollection->getName();
	}

	public function getRelationName(): string
	{
		return $this->relationName;
	}

	public function getDefinition(): RelationInterface
	{
		return $this->ownerCollection->getRelations()->get($this->relationName);
	}

	public function getRelatedSchema(): RepresentationSchema
	{
		return $this->relatedSchema;
	}

	public function getCardinality(): RelationCardinality
	{
		return $this->getDefinition()->getCardinality();
	}

	public function isMany(): bool
	{
		return $this->getCardinality()->isMany();
	}

	public function isSingle(): bool
	{
		return $this->getCardinality()->isSingle();
	}

	public function shouldSkipWhenMissing(): bool
	{
		return $this->skipWhenMissing;
	}
}
