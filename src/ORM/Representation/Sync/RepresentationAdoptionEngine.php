<?php

declare(strict_types=1);

namespace ON\Data\ORM\Representation\Sync;

use ON\Data\Key;
use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\Exception\SyncException;
use ON\Data\ORM\Record\RecordState;
use ON\Data\ORM\Record\RecordStateStore;
use ON\Data\ORM\Relation\RelationStateStore;
use ON\Data\ORM\Relation\RelationTarget;
use ON\Data\ORM\Relation\ToManyRelationState;
use ON\Data\ORM\Representation\Schema\RepresentationFieldSchema;
use ON\Data\ORM\Representation\Schema\RepresentationSchema;
use ON\Data\ORM\Representation\Schema\RepresentationSource;
use ON\Data\ORM\Representation\State\RepresentationState;
use ON\Data\ORM\Representation\State\RepresentationStateStore;
use ON\Data\ORM\Session;

/**
 * Schema-driven attachment of representations into session stores.
 *
 * Distinct from {@see Session::adopt()}, which stores an already-built
 * {@see RepresentationState}. This entry builds flat state or walks a graph,
 * then stores the result.
 *
 * Policy ({@see AdoptionPolicy}): Hydrate | Patch | Create.
 * Flat external PKs come from {@see RepresentationSourceIdentities}.
 */
final class RepresentationAdoptionEngine
{
	private AdoptionRecordResolver $recordResolver;

	public function __construct(
		private RepresentationReader $reader = new RepresentationReader(),
		?AdoptionRecordResolver $recordResolver = null,
		?RepresentationIntentStore $intents = null,
	) {
		$this->recordResolver = $recordResolver ?? new AdoptionRecordResolver(
			reader: $this->reader,
			intents: $intents,
		);
	}

	/**
	 * Build (flat) or walk (graph) and store into $reps / $records.
	 */
	public function attach(
		object $representation,
		RepresentationAdoptionContext $context,
		RecordStateStore $records,
		RepresentationStateStore $reps,
		?RelationStateStore $relations = null,
		RepresentationAttachmentMode $mode = RepresentationAttachmentMode::Add,
	): RepresentationState {
		if ($mode === RepresentationAttachmentMode::Add && $reps->has($representation)) {
			if ($this->isFlatAttachment($context)) {
				$tracked = $reps->get($representation);
				if (! $tracked instanceof RepresentationState) {
					throw new StateException('Tracked representation is missing state.');
				}

				return $tracked;
			}

			$this->attachGraph($representation, $context, $records, $reps);
			$tracked = $reps->get($representation);
			if (! $tracked instanceof RepresentationState) {
				throw new StateException('Graph attachment failed to track the root representation.');
			}

			return $tracked;
		}

		if ($this->isFlatAttachment($context)) {
			$state = $this->buildFlat($representation, $context, $records, $relations);

			foreach ($state->getUniqueRecords() as $record) {
				$records->add($record);
			}

			if ($mode === RepresentationAttachmentMode::Replace && $reps->has($representation)) {
				$reps->remove($representation);
			}

			$reps->add($representation, $state);

			return $state;
		}

		$this->attachGraph($representation, $context, $records, $reps);
		$tracked = $reps->get($representation);
		if (! $tracked instanceof RepresentationState) {
			throw new StateException('Graph attachment failed to track the root representation.');
		}

		return $tracked;
	}

	private function isFlatAttachment(RepresentationAdoptionContext $context): bool
	{
		$intent = $context->getIntent();
		if ($intent instanceof RepresentationIntent) {
			// Identify-then-update / projection overlays: Session routes Replace sync
			// here when isFlatProjection is true (including root-only inbound maps).
			return $intent->isFlatProjection($context->getSchema());
		}

		if ($context->getSchema()->getRelations() !== []) {
			return false;
		}

		// Without intent, only multi-source / non-root projections are flat.
		// Homogeneous root schemas use graph adoption (untracked root sync).
		$sources = $context->getSources();
		foreach ($sources as $source) {
			if (! $source->isRoot()) {
				return true;
			}
		}

		return count($sources) > 1;
	}

	/**
	 * Build flat RepresentationState without storing on RepresentationStateStore.
	 * Hydrate clean records are not added to $records until attached.
	 * Create records are added immediately (matches prior projection builder).
	 */
	private function buildFlat(
		object $representation,
		RepresentationAdoptionContext $context,
		RecordStateStore $records,
		?RelationStateStore $relations = null,
	): RepresentationState {
		$schema = $context->getSchema();
		$sources = $context->getSources();
		if ($sources === []) {
			throw new StateException('Cannot build flat projection representation because the schema has no field sources.');
		}

		$intent = $context->getIntent();
		$opsByPath = $this->indexFlatOps($intent?->getFlatOps() ?? []);
		$recordsBySourceKey = [];
		$rootRecord = null;

		foreach ($sources as $source) {
			$pathKey = $source->getPathKey();
			$op = $opsByPath[$pathKey] ?? null;
			$policy = $this->policyForSource($context, $source, $op);
			$record = $this->resolveSourceRecord(
				$representation,
				$source,
				$policy,
				$context,
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
					if (! $relations instanceof RelationStateStore) {
						throw new StateException(
							'Cannot register flat create relation add without a RelationStateStore.',
						);
					}

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
	 * @return list<RepresentationState>
	 */
	private function attachGraph(
		object $root,
		RepresentationAdoptionContext $context,
		RecordStateStore $records,
		RepresentationStateStore $reps,
	): array {
		if ($reps->get($root) === null) {
			$schema = $context->getSchema();
			$state = $this->attachGraphNode(
				$root,
				$schema,
				$records,
				$reps,
				isRoot: true,
				policy: $context->getPolicy(),
			);
			$adopted = [$state];
		} else {
			$adopted = [];
		}

		$visited = [];
		$this->walkGraph($root, $context->getPolicy(), $records, $reps, $visited, $adopted);

		return $adopted;
	}

	/**
	 * @param array<int, true> $visited
	 * @param list<RepresentationState> $adopted
	 */
	private function walkGraph(
		object $representation,
		AdoptionPolicy $policy,
		RecordStateStore $records,
		RepresentationStateStore $reps,
		array &$visited,
		array &$adopted,
	): void {
		$id = spl_object_id($representation);
		if (array_key_exists($id, $visited)) {
			return;
		}

		$tracked = $reps->get($representation);
		if ($tracked === null) {
			throw new StateException('Cannot walk representation graph because a representation is not tracked.');
		}

		$visited[$id] = true;
		foreach ($tracked->getSchema()->getRelations() as $relationSchema) {
			if ($relationSchema->isMany()) {
				foreach ($this->reader->readItems($representation, $relationSchema, $this->graphAdoptionError(...)) as $item) {
					$this->adoptRelatedAndWalk(
						$item,
						$relationSchema->getRelatedSchema(),
						$policy,
						$records,
						$reps,
						$visited,
						$adopted,
					);
				}

				continue;
			}

			if ($relationSchema->isSingle()) {
				$target = $this->reader->readTarget($representation, $relationSchema, $this->graphAdoptionError(...));
				if ($target !== null) {
					$this->adoptRelatedAndWalk(
						$target,
						$relationSchema->getRelatedSchema(),
						$policy,
						$records,
						$reps,
						$visited,
						$adopted,
					);
				}
			}
		}
	}

	/**
	 * @param array<int, true> $visited
	 * @param list<RepresentationState> $adopted
	 */
	private function adoptRelatedAndWalk(
		object $representation,
		RepresentationSchema $schema,
		AdoptionPolicy $policy,
		RecordStateStore $records,
		RepresentationStateStore $reps,
		array &$visited,
		array &$adopted,
	): void {
		if (! $reps->has($representation)) {
			$adopted[] = $this->attachGraphNode(
				$representation,
				$schema,
				$records,
				$reps,
				isRoot: false,
				policy: $policy,
			);
		}

		$this->walkGraph($representation, $policy, $records, $reps, $visited, $adopted);
	}

	private function attachGraphNode(
		object $representation,
		RepresentationSchema $schema,
		RecordStateStore $records,
		RepresentationStateStore $reps,
		bool $isRoot,
		AdoptionPolicy $policy,
	): RepresentationState {
		$record = match ($policy) {
			AdoptionPolicy::Hydrate => $this->recordResolver->resolveClean(
				$representation,
				$schema,
				$records,
				$isRoot,
			),
			AdoptionPolicy::Patch,
			AdoptionPolicy::Create => $this->recordResolver->resolve(
				$representation,
				$schema,
				$records,
				$isRoot,
			),
		};

		$state = RepresentationState::fromRecords($schema, [
			RepresentationFieldSchema::sourcePathKey([]) => $record,
		]);

		foreach ($state->getUniqueRecords() as $unique) {
			$records->add($unique);
		}

		$reps->add($representation, $state);

		return $state;
	}

	/**
	 * @param non-empty-string $message
	 */
	private function graphAdoptionError(string $message): StateException
	{
		return new StateException(rtrim($message, '.') . ' during graph adoption.');
	}

	private function policyForSource(
		RepresentationAdoptionContext $context,
		RepresentationSource $source,
		?FlatIntentOp $op,
	): AdoptionPolicy {
		if ($op instanceof FlatIntentOp) {
			return $op->isCreate() ? AdoptionPolicy::Create : AdoptionPolicy::Patch;
		}

		$intent = $context->getIntent();
		if ($source->isRoot() && $intent instanceof RepresentationIntent && $intent->isCreate()) {
			return AdoptionPolicy::Create;
		}

		return $context->getPolicy();
	}

	private function resolveSourceRecord(
		object $representation,
		RepresentationSource $source,
		AdoptionPolicy $policy,
		RepresentationAdoptionContext $context,
		RecordStateStore $records,
	): RecordState {
		$collection = $source->getCollection();

		if ($policy === AdoptionPolicy::Create) {
			$values = $this->patchInitialValues($representation, $source);
			$record = RecordState::new($collection, $values);
			$records->add($record);

			return $record;
		}

		if ($policy === AdoptionPolicy::Hydrate) {
			return $this->resolveHydrateRecord($representation, $source, $context, $records);
		}

		$key = $this->resolveExistingKey($representation, $source, $context);
		$values = $this->patchInitialValues($representation, $source);

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

	private function resolveHydrateRecord(
		object $representation,
		RepresentationSource $source,
		RepresentationAdoptionContext $context,
		RecordStateStore $records,
	): RecordState {
		$key = $this->resolveExistingKey($representation, $source, $context, hydrate: true);
		$values = $this->hydrateInitialValues($representation, $source);
		$record = $records->getByKey($key);

		if ($record instanceof RecordState) {
			if ($record->isRemoved()) {
				throw new StateException(sprintf(
					"Cannot build projection representation for collection '%s' because key '%s' is already tracked as removed.",
					$source->getCollection()->getName(),
					$key->getDebugString(),
				));
			}

			return $record;
		}

		return RecordState::clean($key, $values);
	}

	private function resolveExistingKey(
		object $representation,
		RepresentationSource $source,
		RepresentationAdoptionContext $context,
		bool $hydrate = false,
	): Key {
		$fromObject = $this->completeKeyFromObject($representation, $source);
		if ($fromObject instanceof Key) {
			$external = $context->getIdentities()?->getIdentity(
				$source->getPath(),
				$context->getSourceRow(),
			);
			if ($external instanceof Key) {
				$conflict = $external->conflictingIdentityField($fromObject->getValues());
				if ($conflict !== null) {
					throw new StateException(sprintf(
						"Cannot bind projection source for collection '%s' because intent identity field '%s' disagrees with the representation.",
						$source->getCollection()->getName(),
						$conflict,
					));
				}
			}

			return $fromObject;
		}

		$external = $context->getIdentities()?->getIdentity(
			$source->getPath(),
			$context->getSourceRow(),
		);
		if ($external instanceof Key) {
			$conflict = $external->conflictingIdentityField(
				$this->readablePrimaryKeyValues($representation, $source),
			);
			if ($conflict !== null) {
				throw new StateException(sprintf(
					"Cannot bind projection source for collection '%s' because intent identity field '%s' disagrees with the representation.",
					$source->getCollection()->getName(),
					$conflict,
				));
			}

			return $external;
		}

		$collection = $source->getCollection();
		if ($hydrate) {
			throw new StateException(sprintf(
				"Cannot build projection representation for collection '%s' because primary key field '%s' is missing or incomplete.",
				$collection->getName(),
				$collection->getPrimaryKey()[0] ?? 'id',
			));
		}

		foreach ($collection->getPrimaryKey() as $fieldName) {
			if ($source->getFieldPath($fieldName) === null) {
				throw new StateException(sprintf(
					"Cannot bind projection source for collection '%s' because primary key field '%s' is not in the schema and no explicit key was provided.",
					$collection->getName(),
					$fieldName,
				));
			}

			throw new StateException(sprintf(
				"Cannot bind projection source for collection '%s' because primary key field '%s' is missing on the representation.",
				$collection->getName(),
				$fieldName,
			));
		}

		throw new StateException(sprintf(
			"Cannot bind projection source for collection '%s' because the primary key is missing or incomplete.",
			$collection->getName(),
		));
	}

	private function completeKeyFromObject(
		object $representation,
		RepresentationSource $source,
	): ?Key {
		$collection = $source->getCollection();
		$values = [];

		foreach ($collection->getPrimaryKey() as $fieldName) {
			$path = $source->getFieldPath($fieldName);
			if ($path === null) {
				return null;
			}

			try {
				$value = $this->reader->readPath($representation, $path);
			} catch (SyncException) {
				return null;
			}

			if ($value === null) {
				return null;
			}

			$values[$fieldName] = $value;
		}

		return $collection->getKey($values);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function hydrateInitialValues(
		object $representation,
		RepresentationSource $source,
	): array {
		$collection = $source->getCollection();
		$values = [];
		$primaryKey = array_flip($collection->getPrimaryKey());

		foreach ($source->getFields() as $fieldSchema) {
			$fieldName = $fieldSchema->getFieldName();

			try {
				$value = $this->reader->readPath($representation, $fieldSchema->getPath());
			} catch (SyncException) {
				continue;
			}

			if ($value === null && array_key_exists($fieldName, $primaryKey)) {
				continue;
			}

			if ($value === null) {
				continue;
			}

			$values[$fieldName] = $value;
		}

		return $values;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function patchInitialValues(object $representation, RepresentationSource $source): array
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
