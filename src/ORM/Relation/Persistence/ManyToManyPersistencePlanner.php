<?php

declare(strict_types=1);

namespace ON\Data\ORM\Relation\Persistence;

use ON\Data\Definition\Relation\M2MRelation;
use ON\Data\Definition\Relation\RelationInterface;
use ON\Data\ORM\Exception\RelationPersistenceException;
use ON\Data\ORM\Persistence\DeleteCommand;
use ON\Data\ORM\Persistence\InsertCommand;
use ON\Data\ORM\Persistence\PersistenceContext;
use ON\Data\ORM\Relation\RelationChangeInterface;
use ON\Data\ORM\Relation\ToManyRelationState;
use ON\Data\ORM\State\RecordState;

final class ManyToManyPersistencePlanner implements RelationPersistencePlannerInterface
{
	private TrackedRecordResolver $records;
	private ForeignKeyWriter $keys;

	public function __construct(?TrackedRecordResolver $records = null, ?ForeignKeyWriter $keys = null)
	{
		$this->records = $records ?? new TrackedRecordResolver();
		$this->keys = $keys ?? new ForeignKeyWriter();
	}

	public function plan(PersistenceContext $context, RelationInterface $relation, RelationChangeInterface $change): void
	{
		if (! $change instanceof ToManyRelationState) {
			throw new RelationPersistenceException(sprintf(
				"Relation '%s' must be a related collection to use %s.",
				$change->getRelationName(),
				self::class,
			));
		}

		$collection = $change;
		if (! $relation instanceof M2MRelation) {
			throw new RelationPersistenceException(sprintf(
				"Relation '%s' must be a many-to-many relation to use %s.",
				$collection->getRelationName(),
				self::class,
			));
		}

		$throughCollection = $relation->getThrough()->getCollection();
		$owner = $collection->getOwner();

		foreach ($collection->getAdded() as $item) {
			$target = $this->records->resolve($context, $relation, $item, 'target');
			$context->getCommands()->add(new InsertCommand(
				$throughCollection,
				$this->buildThroughValues($relation, $owner, $target),
			));
		}

		foreach ($collection->getRemoved() as $item) {
			$target = $this->records->resolve($context, $relation, $item, 'target');
			$context->getCommands()->add(new DeleteCommand(
				$throughCollection,
				$this->buildThroughValues($relation, $owner, $target),
			));
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	private function buildThroughValues(M2MRelation $relation, RecordState $owner, RecordState $target): array
	{
		return array_replace(
			$this->keys->buildValues(
				$relation->getName(),
				$relation->getInnerKeys(),
				$relation->getThrough()->getInnerKeys(),
				$owner,
				static fn (string $relationName, string $sourceField, int|string $index): RelationPersistenceException => new RelationPersistenceException(sprintf(
					"Relation '%s' through mapping is missing a target field for %s field '%s'.",
					$relationName,
					'owner',
					$sourceField,
				)),
			),
			$this->keys->buildValues(
				$relation->getName(),
				$relation->getOuterKeys(),
				$relation->getThrough()->getOuterKeys(),
				$target,
				static fn (string $relationName, string $sourceField, int|string $index): RelationPersistenceException => new RelationPersistenceException(sprintf(
					"Relation '%s' through mapping is missing a target field for %s field '%s'.",
					$relationName,
					'target',
					$sourceField,
				)),
			),
		);
	}
}
