<?php

declare(strict_types=1);

namespace ON\Data\Query\Relation;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Query\Relation\Loader\LoaderInterface;

final class RelationLoadBranch extends LoadBranch
{
	public function __construct(
		private readonly RelationSelection $selection,
		private readonly LoadBranch $parent,
		private readonly LoaderInterface $loader,
	) {
		$this->parent->addChild($this);
	}

	public function getSelection(): RelationSelection
	{
		return $this->selection;
	}

	public function getRelationRef(): RelationRef
	{
		return $this->selection->getRelationRef();
	}

	public function getProjectionLevel(): RelationRef
	{
		return $this->getRelationRef();
	}

	public function getParent(): LoadBranch
	{
		return $this->parent;
	}

	public function getLoader(): LoaderInterface
	{
		return $this->loader;
	}

	public function getCollection(): CollectionInterface
	{
		return $this->getRelationRef()->getCollection();
	}

	public function isJoinedAttachment(): bool
	{
		return $this->hasQuery()
			&& $this->parent->hasQuery()
			&& $this->getQuery() === $this->parent->getQuery();
	}

	public function returnsMany(): bool
	{
		return $this->getRelationRef()->getDefinition()->getCardinality()->isMany();
	}
}
