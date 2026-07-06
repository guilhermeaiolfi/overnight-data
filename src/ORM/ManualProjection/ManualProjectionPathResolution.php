<?php

declare(strict_types=1);

namespace ON\Data\ORM\ManualProjection;

use ON\Data\ORM\State\RecordState;
use ON\Data\ORM\State\RepresentationBinding;
use ON\Data\ORM\State\RepresentationRelationCardinality;

final class ManualProjectionPathResolution
{
	public function __construct(
		private object $ownerObject,
		private RecordState $owner,
		private string $path,
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
}
