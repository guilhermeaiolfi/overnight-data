<?php

declare(strict_types=1);

namespace ON\Data\ORM\State;

use ON\Data\ORM\Relation\RelationChangeInterface;

final class RepresentationRelationStateItem
{
	public function __construct(
		private RepresentationRelationBinding $binding,
		private RecordState $ownerRecord,
		private string $relationName,
		private ?RelationChangeInterface $loadState = null,
	) {
	}

	public function getPath(): string
	{
		return $this->binding->getPath();
	}

	public function getBinding(): RepresentationRelationBinding
	{
		return $this->binding;
	}

	public function getOwnerRecord(): RecordState
	{
		return $this->ownerRecord;
	}

	public function getRelationName(): string
	{
		return $this->relationName;
	}

	public function getLoadState(): ?RelationChangeInterface
	{
		return $this->loadState;
	}
}
