<?php

declare(strict_types=1);

namespace ON\Data\ORM\Representation\State\Projection;

use ON\Data\Key;
use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\Exception\SyncException;
use ON\Data\ORM\Record\RecordState;
use ON\Data\ORM\Record\RecordStateStore;
use ON\Data\ORM\Relation\RelationStateStore;
use ON\Data\ORM\Relation\RelationTarget;
use ON\Data\ORM\Relation\ToManyRelationState;
use ON\Data\ORM\Representation\Schema\RepresentationSchema;
use ON\Data\ORM\Representation\Schema\Shape\RepresentationSource;
use ON\Data\ORM\Representation\State\RepresentationState;
use ON\Data\ORM\Representation\Sync\FlatIntentOp;
use ON\Data\ORM\Representation\Sync\RepresentationIntent;
use ON\Data\ORM\Representation\Sync\RepresentationIntentLifecycle;
use ON\Data\ORM\Representation\Sync\RepresentationReader;

/**
 * Builds RepresentationState for inbound flat projection intents (no SQL row).
 *
 * Sibling of QueryRepresentationStateBuilder: resolves RecordStates from
 * RepresentationIntent lifecycle/flatOps and DTO primary-key paths instead of
 * query result identity columns.
 */
final class ProjectionRepresentationStateBuilder
{
	public function __construct(
		private ?RepresentationReader $reader = null,
	) {
		$this->reader ??= new RepresentationReader();
	}

	public function build(
		object $representation,
		RepresentationIntent $intent,
		RepresentationSchema $schema,
		RecordStateStore $records,
		RelationStateStore $relations,
	): RepresentationState {
		if ($schema->getRelations() !== []) {
			throw new StateException('Cannot build flat projection representation because the schema contains relation schemas. Use entity-shaped sync for nested graphs.');
		}

		$sources = RepresentationSource::fromRepresentationSchema($schema);
		if ($sources === []) {
			throw new StateException('Cannot build flat projection representation because the schema has no field sources.');
		}

		$opsByPath = $this->indexFlatOps($intent->getFlatOps());
		$recordsBySourceKey = [];
		$rootRecord = null;

		foreach ($sources as $source) {
			$pathKey = $source->getPathKey();
			$op = $opsByPath[$pathKey] ?? null;
			$record = $this->resolveSourceRecord(
				$representation,
				$source,
				$intent,
				$op,
				$records,
			);
			$recordsBySourceKey[$pathKey] = $record;

			if ($source->isRoot()) {
				$rootRecord = $record;
			}
		}

		if ($rootRecord instanceof RecordState) {
			foreach ($sources as $source) {
				if ($source->isRoot()) {
					continue;
				}

				$pathKey = $source->getPathKey();
				$op = $opsByPath[$pathKey] ?? null;
				if ($op instanceof FlatIntentOp && $op->isCreate()) {
					$this->registerRelationAdd(
						$rootRecord,
						$source,
						$recordsBySourceKey[$pathKey],
						$relations,
					);
				}
			}
		}

		return RepresentationState::fromRecords($schema, $recordsBySourceKey);
	}

	/**
	 * @param list<FlatIntentOp> $ops
	 *
	 * @return array<string, FlatIntentOp>
	 */
	private function indexFlatOps(array $ops): array
	{
		$indexed = [];
		foreach ($ops as $op) {
			$indexed[$op->getPath()] = $op;
		}

		return $indexed;
	}

	private function resolveSourceRecord(
		object $representation,
		RepresentationSource $source,
		RepresentationIntent $intent,
		?FlatIntentOp $op,
		RecordStateStore $records,
	): RecordState {
		$collection = $source->getCollection();
		$create = $op?->isCreate()
			?? ($source->isRoot() && $intent->getLifecycle() === RepresentationIntentLifecycle::Create);

		if ($create) {
			$values = $this->initialValues($representation, $source);
			$record = RecordState::new($collection, $values);
			$records->add($record);

			return $record;
		}

		$key = $this->resolveKey($representation, $source, $intent, $op);
		$values = $this->initialValues($representation, $source);

		return $records->bindExisting(
			$key,
			$values,
			sprintf(
				"Cannot bind projection source '%s' because key '%s' is already tracked as removed.",
				$source->getPathKey() === '' ? '[root]' : $source->getPathKey(),
				$key->getDebugString(),
			),
		);
	}

	private function resolveKey(
		object $representation,
		RepresentationSource $source,
		RepresentationIntent $intent,
		?FlatIntentOp $op,
	): Key {
		$collection = $source->getCollection();
		if ($op instanceof FlatIntentOp && $op->getKey() !== null) {
			return $collection->getKey($op->getKey());
		}

		if ($source->isRoot() && $intent->getIdentity() !== null) {
			$key = $collection->getKey($intent->getIdentity());
			$conflict = $key->conflictingIdentityField(
				$this->readablePrimaryKeyValues($representation, $source),
			);
			if ($conflict !== null) {
				throw new StateException(sprintf(
					"Cannot bind projection source for collection '%s' because intent identity field '%s' disagrees with the representation.",
					$collection->getName(),
					$conflict,
				));
			}

			return $key;
		}

		$keyValues = [];
		foreach ($collection->getPrimaryKey() as $fieldName) {
			$path = $source->getFieldPath($fieldName);
			if ($path === null) {
				throw new StateException(sprintf(
					"Cannot bind projection source for collection '%s' because primary key field '%s' is not in the schema and no explicit key was provided.",
					$collection->getName(),
					$fieldName,
				));
			}

			try {
				$value = $this->reader->readPath($representation, $path);
			} catch (SyncException) {
				$value = null;
			}

			if ($value === null) {
				throw new StateException(sprintf(
					"Cannot bind projection source for collection '%s' because primary key field '%s' is missing on the representation.",
					$collection->getName(),
					$fieldName,
				));
			}

			$keyValues[$fieldName] = $value;
		}

		return $collection->getKey($keyValues);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function readablePrimaryKeyValues(object $representation, RepresentationSource $source): array
	{
		$values = [];
		foreach ($source->getCollection()->getPrimaryKey() as $fieldName) {
			$path = $source->getFieldPath($fieldName);
			if ($path === null) {
				continue;
			}

			try {
				$values[$fieldName] = $this->reader->readPath($representation, $path);
			} catch (SyncException) {
			}
		}

		return $values;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function initialValues(object $representation, RepresentationSource $source): array
	{
		$values = [];
		foreach ($source->getFields() as $fieldSchema) {
			try {
				$values[$fieldSchema->getFieldName()] = $this->reader->readPath(
					$representation,
					$fieldSchema->getPath(),
				);
			} catch (SyncException) {
			}
		}

		return $values;
	}

	private function registerRelationAdd(
		RecordState $owner,
		RepresentationSource $source,
		RecordState $relatedRecord,
		RelationStateStore $relations,
	): void {
		$path = $source->getPath();
		if ($path === [] || count($path) !== 1) {
			throw new StateException(sprintf(
				"Flat create only supports a single relation path segment; got '%s'.",
				$source->getPathKey(),
			));
		}

		$relationName = $path[0];
		$ownerCollection = $owner->getCollection();
		if (! $ownerCollection->hasRelation($relationName)) {
			throw new StateException(sprintf(
				"Cannot register flat create for '%s' because collection '%s' has no relation '%s'.",
				$source->getPathKey(),
				$ownerCollection->getName(),
				$relationName,
			));
		}

		$definition = $ownerCollection->getRelation($relationName);
		$relatedSchema = RepresentationSchema::forPrimaryKey($relatedRecord->getCollection());
		$target = RelationTarget::record($relatedRecord);
		$state = $relations->getOrCreate(
			$owner,
			$relationName,
			$definition->getCardinality(),
			$relatedSchema,
		);

		if ($state instanceof ToManyRelationState) {
			$state->addTarget($target);

			return;
		}

		$state->setTarget($target);
	}
}
