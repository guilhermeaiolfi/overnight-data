<?php

declare(strict_types=1);

namespace ON\Data\ORM\Representation\Schema\Manual;

use ON\Data\ORM\Record\RecordState;
use ON\Data\ORM\Representation\Schema\RepresentationSchema;
use ON\Data\Definition\Relation\RelationCardinality;
use ON\Data\Query\Exception\UnknownQueryFieldException;
/**
 * Fluent handle for a concrete relation target during manual representation building.
 *
 * Exists to expose field()/all()/end() on relation items after create(),
 * existing(), or tracked(), mirroring query-style ergonomics without being a
 * query source.
 */
final class RelationRepresentationSource implements ManualRepresentationSourceInterface
{
	/** @var list<PendingRepresentationAdoption> */
	private array $pendingEnrollments;

	/**
	 * @param list<PendingRepresentationAdoption> $pendingEnrollments
	 */
	public function __construct(
		private RecordState $owner,
		private string $relationName,
		private RelationCardinality $cardinality,
		private RepresentationSchema $relatedSchema,
		private RecordState $targetRecord,
		private object $targetObject,
		private bool $objectShaped,
		array $pendingEnrollments = [],
	) {
		$this->pendingEnrollments = array_values($pendingEnrollments);
	}

	public function getOwner(): RecordState
	{
		return $this->owner;
	}

	public function getRelationName(): string
	{
		return $this->relationName;
	}

	public function getCardinality(): RelationCardinality
	{
		return $this->cardinality;
	}

	public function getRelatedSchema(): RepresentationSchema
	{
		return $this->relatedSchema;
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
	 * @return list<PendingRepresentationAdoption>
	 */
	public function pullPendingEnrollments(): array
	{
		$pending = $this->pendingEnrollments;
		$this->pendingEnrollments = [];

		return $pending;
	}

	/**
	 * @return list<string>
	 */
	public function getRelationPath(): array
	{
		return [$this->relationName];
	}

	public function field(string $name): PropertyRef
	{
		$collection = $this->targetRecord->getCollection();
		if (! $collection->hasField($name)) {
			throw new UnknownQueryFieldException(sprintf("Unknown field '%s' on collection '%s'.", $name, $collection->getName()));
		}

		return new PropertyRef($this, $name);
	}

	public function all(): AllProperties
	{
		return new AllProperties($this);
	}

	public function end(): object
	{
		return $this->targetObject;
	}

	public function __get(string $name): PropertyRef
	{
		return $this->field($name);
	}
}
