<?php

declare(strict_types=1);

namespace ON\Data\ORM\Compiler\ManualProjection;

/**
 * Resolved fromPath() context: owner record, relation metadata, and the related
 * binding branch to reuse for new relation targets.
 *
 * Exists as a small value object so Builder does not re-walk owner bindings
 * when attaching path-based targets.
 */
use ON\Data\ORM\State\RecordState;
use ON\Data\ORM\State\RepresentationBinding;
use ON\Data\ORM\State\RepresentationRelationCardinality;

final class PathResolution
{
	public function __construct(
		private object $ownerObject,
		private RecordState $owner,
		private string $relationName,
		private RepresentationRelationCardinality $cardinality,
		private RepresentationBinding $relatedBinding,
	) {
	}

	public function getOwnerObject(): object
	{
		return $this->ownerObject;
	}

	public function getOwner(): RecordState
	{
		return $this->owner;
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
}
