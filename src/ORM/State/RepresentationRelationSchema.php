<?php

declare(strict_types=1);

namespace ON\Data\ORM\State;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Relation\RelationInterface;

/**
 * One structural relation representation path bound to an owner collection,
 * relation name, and reusable related RepresentationSchema branch.
 *
 * Exists so graph sync and relation runtime state can share one recursive
 * binding model without duplicating per-child binding templates.
 */
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

	public function isMany(): bool
	{
		return $this->getDefinition()->getCardinality() === 'many';
	}

	public function isSingle(): bool
	{
		return $this->getDefinition()->getCardinality() === 'single';
	}

	public function shouldSkipWhenMissing(): bool
	{
		return $this->skipWhenMissing;
	}
}
