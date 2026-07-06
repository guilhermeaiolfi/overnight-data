<?php

declare(strict_types=1);

namespace ON\Data\ORM\ManualProjection;

use ON\Data\ORM\State\RecordState;
use ON\Data\ORM\State\RepresentationBinding;
use ON\Data\ORM\State\RepresentationRelationCardinality;
use ON\Data\Query\Exception\UnknownQueryFieldException;

final class ManualProjectionTarget implements ManualProjectionPropertySource
{
	public function __construct(
		private ManualProjectionBuilder $builder,
		private RecordState $owner,
		private string $relationName,
		private RepresentationRelationCardinality $cardinality,
		private RepresentationBinding $relatedBinding,
		private RecordState $targetRecord,
		private object $targetObject,
		private bool $objectShaped,
	) {
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

	public function getTargetRecord(): RecordState
	{
		return $this->targetRecord;
	}

	public function getTargetObject(): object
	{
		return $this->targetObject;
	}

	public function isObjectShaped(): bool
	{
		return $this->objectShaped;
	}

	/**
	 * @return list<string>
	 */
	public function getRelationPath(): array
	{
		return [$this->relationName];
	}

	public function field(string $name): ManualProjectionPropertyRef
	{
		$collection = $this->targetRecord->getCollection();
		if (! $collection->hasField($name)) {
			throw new UnknownQueryFieldException(sprintf("Unknown field '%s' on collection '%s'.", $name, $collection->getName()));
		}

		return new ManualProjectionPropertyRef($this, $name);
	}

	public function all(): ManualProjectionAllProperties
	{
		return new ManualProjectionAllProperties($this);
	}

	public function end(): object
	{
		return $this->builder->finalizeObjectShapedTarget($this);
	}

	public function __get(string $name): ManualProjectionPropertyRef
	{
		return $this->field($name);
	}
}
