<?php

declare(strict_types=1);

namespace ON\Data\ORM\State;

/**
 * Concrete runtime attachment for one relation representation path: the
 * structural relation binding plus the owner record it is bound to.
 *
 * Relation loadedness and membership live in the relation runtime stores
 * (ToManyRelationState / ToOneRelationState); this item only names the owner
 * record and relation so callers can resolve that runtime state.
 */
final class RepresentationRelationStateItem
{
	public function __construct(
		private RepresentationRelationBinding $binding,
		private RecordState $ownerRecord,
		private string $relationName,
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
}
