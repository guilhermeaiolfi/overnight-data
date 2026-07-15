<?php

declare(strict_types=1);

namespace ON\Data\ORM\Representation\State;

use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\Record\RecordState;
use ON\Data\ORM\Representation\Schema\RepresentationRelationSchema;

/**
 * Concrete runtime attachment for one relation representation path: the
 * structural relation schema plus the owner record it is bound to.
 *
 * Relation loadedness and membership live in the relation runtime stores
 * (ToManyRelationState / ToOneRelationState); this item only names the owner
 * record and relation so callers can resolve that runtime state.
 */
final class RepresentationRelationStateItem
{
	public static function createOne(
		RepresentationRelationSchema $relationSchema,
		RecordState $ownerRecord,
	): self {
		if ($relationSchema->getOwnerCollectionName() !== $ownerRecord->getCollection()->getName()) {
			throw new StateException(sprintf(
				"Representation relation path '%s' targets collection '%s', not '%s'.",
				$relationSchema->getPath(),
				$relationSchema->getOwnerCollectionName(),
				$ownerRecord->getCollection()->getName()
			));
		}

		return new self(
			$relationSchema,
			$ownerRecord,
			$relationSchema->getRelationName()
		);
	}

	public function __construct(
		private RepresentationRelationSchema $schema,
		private RecordState $ownerRecord,
		private string $relationName,
	) {
	}

	public function getPath(): string
	{
		return $this->schema->getPath();
	}

	public function getSchema(): RepresentationRelationSchema
	{
		return $this->schema;
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
