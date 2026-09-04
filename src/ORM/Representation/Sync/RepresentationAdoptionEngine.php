<?php

declare(strict_types=1);

namespace ON\Data\ORM\Representation\Sync;

use ON\Data\Key;
use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\Record\RecordState;
use ON\Data\ORM\Record\RecordStateStore;
use ON\Data\ORM\Relation\RelationStateStore;
use ON\Data\ORM\Relation\RelationTarget;
use ON\Data\ORM\Relation\ToManyRelationState;
use ON\Data\ORM\Relation\ToOneRelationState;
use ON\Data\ORM\Representation\Schema\Query\QuerySourceIdentities;
use ON\Data\ORM\Representation\State\RepresentationState;
use ON\Data\ORM\Representation\State\RepresentationStateStore;
use ON\Data\ORM\Session;
use ON\Data\Schema\RelationLoadKnowledge;
use ON\Data\Schema\RepresentationFieldSchema;
use ON\Data\Schema\RepresentationRelationSchema;
use ON\Data\Schema\RepresentationSchema;
use ON\Data\Schema\RepresentationSource;

/**
 * Schema-driven attachment of representations into session stores.
 *
 * Distinct from {@see Session::adopt()}, which stores an already-built
 * {@see RepresentationState}. This entry resolves each source's policy,
 * identity, and initial values from one field read, binds Create /
 * existing-clean / existing-patched records, then walks relation children.
 *
 * Policy ({@see AdoptionPolicy}): Hydrate | Identify | Patch | Create.
 * Query hydrate prefers values on {@see RepresentationAdoptionContext::getSourceRow()}.
 * Hydrate also constructs loaded relation state from walked children
 * ({@see ToManyRelationState::full()}, {@see ToManyRelationState::partial()},
 * {@see ToOneRelationState}), matching {@see RecordState::clean()} for rows.
 */
final class RepresentationAdoptionEngine
{
	public function __construct(
		private RepresentationReader $reader = new RepresentationReader(),
		private ?RepresentationIntentStore $intents = null,
	) {
	}

	/**
	 * Bind this schema's sources, store the representation, then walk nested relations.
	 */
	public function attach(
		object $representation,
		RepresentationAdoptionContext $context,
		RecordStateStore $records,
		RepresentationStateStore $reps,
		?RelationStateStore $relations = null,
		RepresentationAttachmentMode $mode = RepresentationAttachmentMode::Add,
	): RepresentationState {
		$visited = [];

		return $this->attachRecursive(
			$representation,
			$context,
			$records,
			$reps,
			$relations,
			$mode,
			$visited,
		);
	}

	/**
	 * @param array<int, true> $visited
	 */
	private function attachRecursive(
		object $representation,
		RepresentationAdoptionContext $context,
		RecordStateStore $records,
		RepresentationStateStore $reps,
		?RelationStateStore $relations,
		RepresentationAttachmentMode $mode,
		array &$visited,
	): RepresentationState {
		if ($mode === RepresentationAttachmentMode::Add && $reps->has($representation)) {
			$tracked = $reps->get($representation);
			if (! $tracked instanceof RepresentationState) {
				throw new StateException('Tracked representation is missing state.');
			}

			$this->walkRelations(
				$representation,
				$context,
				$records,
				$reps,
				$relations,
				$visited,
			);

			return $tracked;
		}

		$state = $this->bindSources($representation, $context, $records, $relations);

		foreach ($state->getUniqueRecords() as $record) {
			$records->add($record);
		}

		if ($mode === RepresentationAttachmentMode::Replace && $reps->has($representation)) {
			$reps->remove($representation);
		}

		$reps->add($representation, $state);

		$this->walkRelations(
			$representation,
			$context,
			$records,
			$reps,
			$relations,
			$visited,
		);

		return $state;
	}

	/**
	 * @param array<int, true> $visited
	 */
	private function walkRelations(
		object $representation,
		RepresentationAdoptionContext $context,
		RecordStateStore $records,
		RepresentationStateStore $reps,
		?RelationStateStore $relations,
		array &$visited,
	): void {
		$id = spl_object_id($representation);
		if (array_key_exists($id, $visited)) {
			return;
		}

		$visited[$id] = true;
		$tracked = $reps->get($representation);
		$schema = $tracked instanceof RepresentationState
			? $tracked->getSchema()
			: $context->getSchema();
		$sourceRow = $context->getSourceRow();

		foreach ($schema->getRelations() as $relationSchema) {
			if ($relationSchema->getOwnerCollectionName() !== $schema->getCollection()->getName()) {
				throw new StateException(sprintf(
					"Cannot synchronize untracked root representation because untracked root sync needs a schema targeting one collection; path '%s' targets collection '%s' after '%s'.",
					$relationSchema->getPath(),
					$relationSchema->getOwnerCollectionName(),
					$schema->getCollection()->getName(),
				));
			}

			$rawChildren = is_array($sourceRow)
				? ($sourceRow[$relationSchema->getPath()] ?? null)
				: null;

			if ($relationSchema->isMany()) {
				$items = [];
				foreach ($this->reader->readItems($representation, $relationSchema, $this->relationError(...))->requireValue() as $index => $item) {
					$childRow = $this->childSourceRow($rawChildren, $index, many: true);
					$this->attachRecursive(
						$item,
						$this->childContext($context, $relationSchema->getRelatedSchema(), $childRow),
						$records,
						$reps,
						$relations,
						RepresentationAttachmentMode::Add,
						$visited,
					);
					$items[] = $item;
				}

				$this->hydrateLoadedMany(
					$context,
					$tracked,
					$relationSchema,
					$relations,
					$items,
				);

				continue;
			}

			if ($relationSchema->isSingle()) {
				$target = $this->reader->readTarget($representation, $relationSchema, $this->relationError(...))->requireValue();
				if ($target !== null) {
					$childRow = $this->childSourceRow($rawChildren, 0, many: false);
					$this->attachRecursive(
						$target,
						$this->childContext($context, $relationSchema->getRelatedSchema(), $childRow),
						$records,
						$reps,
						$relations,
						RepresentationAttachmentMode::Add,
						$visited,
					);
				}

				$this->hydrateLoadedOne(
					$context,
					$tracked,
					$relationSchema,
					$relations,
					$target,
				);
			}
		}
	}

	private function childContext(
		RepresentationAdoptionContext $parent,
		RepresentationSchema $relatedSchema,
		?array $childRow,
	): RepresentationAdoptionContext {
		$policy = $parent->getPolicy();
		if ($policy !== AdoptionPolicy::Hydrate && $policy !== AdoptionPolicy::Identify) {
			$policy = AdoptionPolicy::Create;
		}

		return new RepresentationAdoptionContext(
			schema: $relatedSchema,
			policy: $policy,
			identities: new QuerySourceIdentities($relatedSchema->getSources()),
			sourceRow: $childRow,
		);
	}

	/**
	 * @param mixed $rawChildren
	 *
	 * @return array<string, mixed>|null
	 */
	private function childSourceRow(mixed $rawChildren, int $index, bool $many): ?array
	{
		if (! is_array($rawChildren)) {
			return null;
		}

		$child = $many ? ($rawChildren[$index] ?? null) : $rawChildren;
		if (! is_array($child)) {
			return null;
		}

		/** @var array<string, mixed> $child */
		return $child;
	}

	/**
	 * @param list<object> $items
	 */
	private function hydrateLoadedMany(
		RepresentationAdoptionContext $context,
		?RepresentationState $ownerState,
		RepresentationRelationSchema $relationSchema,
		?RelationStateStore $relations,
		array $items,
	): void {
		$owner = $this->hydrateOwnerRecord($context, $ownerState, $relationSchema, $relations);
		if (! $owner instanceof RecordState || ! $relations instanceof RelationStateStore) {
			return;
		}

		$relationName = $relationSchema->getRelationName();
		$relatedSchema = $relationSchema->getRelatedSchema();
		$state = match ($relationSchema->getLoadKnowledge()) {
			RelationLoadKnowledge::Full => ToManyRelationState::full($owner, $relationName, $relatedSchema, $items),
			RelationLoadKnowledge::Partial => ToManyRelationState::partial($owner, $relationName, $relatedSchema, $items),
			RelationLoadKnowledge::Unknown => new ToManyRelationState($owner, $relationName, $relatedSchema, $items),
		};
		$relations->add($state);
	}

	private function hydrateLoadedOne(
		RepresentationAdoptionContext $context,
		?RepresentationState $ownerState,
		RepresentationRelationSchema $relationSchema,
		?RelationStateStore $relations,
		?object $target,
	): void {
		$owner = $this->hydrateOwnerRecord($context, $ownerState, $relationSchema, $relations);
		if (! $owner instanceof RecordState || ! $relations instanceof RelationStateStore) {
			return;
		}

		$relations->add(new ToOneRelationState(
			$owner,
			$relationSchema->getRelationName(),
			$relationSchema->getRelatedSchema(),
			$target,
		));
	}

	private function hydrateOwnerRecord(
		RepresentationAdoptionContext $context,
		?RepresentationState $ownerState,
		RepresentationRelationSchema $relationSchema,
		?RelationStateStore $relations,
	): ?RecordState {
		if ($context->getPolicy() !== AdoptionPolicy::Hydrate) {
			return null;
		}

		if (! $relations instanceof RelationStateStore || ! $ownerState instanceof RepresentationState) {
			return null;
		}

		$owner = $ownerState->getOwnerRecord($relationSchema->getRelationName());
		if ($relations->has($owner, $relationSchema->getRelationName())) {
			return null;
		}

		return $owner;
	}

	/**
	 * Bind every source on this schema into one {@see RepresentationState}.
	 */
	private function bindSources(
		object $representation,
		RepresentationAdoptionContext $context,
		RecordStateStore $records,
		?RelationStateStore $relations = null,
	): RepresentationState {
		$schema = $context->getSchema();
		$sources = $context->getSources();

		if ($sources === [] && $schema->getRelations() === []) {
			throw new StateException(
				'Cannot synchronize untracked root representation because untracked root sync needs a schema targeting one collection.',
			);
		}

		$opsByPath = $this->indexFlatOps($context->getIntent()?->getFlatOps() ?? []);
		$recordsBySourceKey = [];
		$rootRecord = null;
		$flatCreates = [];

		foreach ($schema->getRelations() as $relationSchema) {
			if ($relationSchema->getOwnerCollectionName() !== $schema->getCollection()->getName()) {
				throw new StateException(sprintf(
					"Cannot synchronize untracked root representation because untracked root sync needs a schema targeting one collection; path '%s' targets collection '%s' after '%s'.",
					$relationSchema->getPath(),
					$relationSchema->getOwnerCollectionName(),
					$schema->getCollection()->getName(),
				));
			}
		}

		if ($sources === []) {
			$rootRecord = $this->resolveEmptyRoot($context, $records);
			$recordsBySourceKey[RepresentationFieldSchema::sourcePathKey([])] = $rootRecord;
		}

		foreach ($sources as $source) {
			$this->assertSourceFieldCollections($source);

			$pathKey = $source->getPathKey();
			$op = $opsByPath[$pathKey] ?? null;
			$policy = $this->policyForSource($representation, $context, $source, $op);
			[$readsByPath, $presentValues] = $this->readSourceFields($representation, $source, $context);

			$record = $this->bindSourceRecord(
				$source,
				$policy,
				$readsByPath,
				$presentValues,
				$context,
				$records,
			);
			$recordsBySourceKey[$pathKey] = $record;

			if ($source->isRoot()) {
				$rootRecord = $record;
			}

			if ($op instanceof FlatIntentOp && $op->isCreate()) {
				$flatCreates[] = $source;
			}
		}

		if ($rootRecord instanceof RecordState) {
			foreach ($flatCreates as $source) {
				if ($source->isRoot()) {
					continue;
				}

				if (! $relations instanceof RelationStateStore) {
					throw new StateException(
						'Cannot register flat create relation add without a RelationStateStore.',
					);
				}

				$related = $recordsBySourceKey[$source->getPathKey()] ?? null;
				if ($related instanceof RecordState) {
					$this->registerRelationAdd(
						$rootRecord,
						$source,
						$related,
						$relations,
					);
				}
			}
		}

		return RepresentationState::fromRecords($schema, $recordsBySourceKey);
	}

	private function resolveEmptyRoot(
		RepresentationAdoptionContext $context,
		RecordStateStore $records,
	): RecordState {
		$collection = $context->getSchema()->getCollection();
		$policy = $context->getPolicy();
		$identity = $context->getIdentities()?->getIdentity([], $context->getSourceRow());

		if ($policy === AdoptionPolicy::Hydrate || $policy === AdoptionPolicy::Identify) {
			if (! $identity instanceof Key) {
				if ($policy === AdoptionPolicy::Hydrate) {
					throw StateException::writableResultMissingIdentity(
						$collection->getName(),
						$collection->getPrimaryKey(),
					);
				}

				throw new StateException(sprintf(
					"Cannot build projection representation for collection '%s' because primary key field '%s' is missing or incomplete.",
					$collection->getName(),
					$collection->getPrimaryKey()[0] ?? 'id',
				));
			}

			$existing = $records->getByKey($identity);
			if ($existing instanceof RecordState) {
				if ($existing->isRemoved()) {
					throw new StateException(sprintf(
						"Cannot build projection representation for collection '%s' because key '%s' is already tracked as removed.",
						$collection->getName(),
						$identity->getDebugString(),
					));
				}

				return $existing;
			}

			return RecordState::clean($identity, $identity->getValues());
		}

		if ($policy === AdoptionPolicy::Patch && $identity instanceof Key) {
			return $records->bindExisting(
				$identity,
				[],
				sprintf(
					"Cannot bind projection source '%s' because key '%s' is already tracked as removed.",
					'[root]',
					$identity->getDebugString(),
				),
			);
		}

		return RecordState::new($collection, []);
	}

	private function assertSourceFieldCollections(RepresentationSource $source): void
	{
		$expected = $source->getCollection()->getName();
		foreach ($source->getFields() as $field) {
			if ($field->getCollectionName() !== $expected) {
				throw new StateException(sprintf(
					"Cannot synchronize untracked root representation because untracked root sync needs a schema targeting one collection; path '%s' targets collection '%s' after '%s'.",
					$field->getPath(),
					$field->getCollectionName(),
					$expected,
				));
			}
		}
	}

	private function policyForSource(
		object $representation,
		RepresentationAdoptionContext $context,
		RepresentationSource $source,
		?FlatIntentOp $op,
	): AdoptionPolicy {
		if ($op instanceof FlatIntentOp) {
			return $op->isCreate() ? AdoptionPolicy::Create : AdoptionPolicy::Patch;
		}

		$policy = $context->getPolicy();
		if ($policy === AdoptionPolicy::Hydrate || $policy === AdoptionPolicy::Identify) {
			return $policy;
		}

		if ($source->isRoot() && $this->intents instanceof RepresentationIntentStore) {
			$stored = $this->intents->get($representation);
			if ($stored instanceof RepresentationIntent) {
				return $stored->isCreate() ? AdoptionPolicy::Create : AdoptionPolicy::Patch;
			}
		}

		$intent = $context->getIntent();
		if ($source->isRoot() && $intent instanceof RepresentationIntent && $intent->isCreate()) {
			return AdoptionPolicy::Create;
		}

		return $policy;
	}

	/**
	 * Read each place once. Identity uses the source binding path.
	 *
	 * @return array{0: array<string, PathReadResult>, 1: array<string, mixed>}
	 */
	private function readSourceFields(
		object $representation,
		RepresentationSource $source,
		RepresentationAdoptionContext $context,
	): array {
		$readsByPath = [];

		foreach ($source->getFields() as $fieldSchema) {
			$path = $fieldSchema->getPath();
			if (array_key_exists($path, $readsByPath)) {
				continue;
			}

			$readsByPath[$path] = $this->readPlaceValue(
				$representation,
				$path,
				$context->getSourceRow(),
			);
		}

		return [$readsByPath, $this->agreedPresentValues($source, $readsByPath)];
	}

	/**
	 * Fold present places onto collection fields.
	 *
	 * Writable aliases of the same field must agree and win. Read-only aliases
	 * are output: they fill a field only when no writable place is present
	 * (implicit identity, read-only-only projections).
	 *
	 * @param array<string, PathReadResult> $readsByPath
	 *
	 * @return array<string, mixed>
	 */
	private function agreedPresentValues(RepresentationSource $source, array $readsByPath): array
	{
		$writable = [];
		$writablePaths = [];
		$readOnly = [];
		$readOnlyPaths = [];

		foreach ($source->getFields() as $fieldSchema) {
			$result = $readsByPath[$fieldSchema->getPath()] ?? null;
			if (! $result instanceof PathReadResult || ! $result->isPresent()) {
				continue;
			}

			if ($fieldSchema->isWritable()) {
				$this->assignPresentAlias(
					$source,
					$writable,
					$writablePaths,
					$fieldSchema,
					$result->requireValue(),
				);

				continue;
			}

			$this->assignPresentAlias(
				$source,
				$readOnly,
				$readOnlyPaths,
				$fieldSchema,
				$result->requireValue(),
			);
		}

		return $writable + array_diff_key($readOnly, $writable);
	}

	/**
	 * @param array<string, mixed> $values
	 * @param array<string, string> $paths
	 */
	private function assignPresentAlias(
		RepresentationSource $source,
		array &$values,
		array &$paths,
		RepresentationFieldSchema $fieldSchema,
		mixed $value,
	): void {
		$fieldName = $fieldSchema->getFieldName();
		if (array_key_exists($fieldName, $values) && $values[$fieldName] !== $value) {
			throw new StateException(sprintf(
				"Cannot bind projection source for collection '%s' because places '%s' and '%s' disagree on field '%s'.",
				$source->getCollection()->getName(),
				$paths[$fieldName],
				$fieldSchema->getPath(),
				$fieldName,
			));
		}

		$values[$fieldName] = $value;
		$paths[$fieldName] = $fieldSchema->getPath();
	}

	/**
	 * @param array<string, PathReadResult> $readsByPath
	 * @param array<string, mixed> $presentValues
	 */
	private function bindSourceRecord(
		RepresentationSource $source,
		AdoptionPolicy $policy,
		array $readsByPath,
		array $presentValues,
		RepresentationAdoptionContext $context,
		RecordStateStore $records,
	): RecordState {
		$collection = $source->getCollection();
		$values = $this->initialValuesForPolicy($source, $policy, $presentValues);

		if ($policy === AdoptionPolicy::Create) {
			$key = $this->tryCompleteKey($source, $context, $readsByPath);
			if ($key instanceof Key) {
				$existing = $records->getActive(
					$key,
					sprintf(
						"Cannot adopt representation for collection '%s' because key '%s' is already tracked as removed.",
						$collection->getName(),
						$key->getDebugString(),
					),
				);
				if ($existing instanceof RecordState) {
					return $existing;
				}
			}

			$record = RecordState::new($collection, $values);
			$records->add($record);

			return $record;
		}

		if ($policy === AdoptionPolicy::Hydrate || $policy === AdoptionPolicy::Identify) {
			$key = $this->resolveExistingKey($source, $context, $readsByPath, hydrate: true);
			$record = $records->getByKey($key);

			if ($record instanceof RecordState) {
				if ($record->isRemoved()) {
					throw new StateException(sprintf(
						"Cannot build projection representation for collection '%s' because key '%s' is already tracked as removed.",
						$collection->getName(),
						$key->getDebugString(),
					));
				}

				return $record;
			}

			return RecordState::clean($key, $values);
		}

		$key = $this->resolveExistingKey($source, $context, $readsByPath);

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

	/**
	 * Hydrate/Identify omit nulls. Patch/Create keep non-key nulls and drop null primary keys.
	 *
	 * @param array<string, mixed> $presentValues
	 *
	 * @return array<string, mixed>
	 */
	private function initialValuesForPolicy(
		RepresentationSource $source,
		AdoptionPolicy $policy,
		array $presentValues,
	): array {
		$omitAllNulls = $policy === AdoptionPolicy::Hydrate || $policy === AdoptionPolicy::Identify;
		$primaryKey = $source->getCollection()->getPrimaryKey();
		$values = [];

		foreach ($presentValues as $fieldName => $value) {
			if ($value === null && ($omitAllNulls || in_array($fieldName, $primaryKey, true))) {
				continue;
			}

			$values[$fieldName] = $value;
		}

		return $values;
	}

	/**
	 * Create uses an assigned key only to reuse an already-tracked record.
	 *
	 * Any identity failure — missing key, conflict with intent identity — is
	 * treated as "no key". Do not narrow this catch: Create must not start
	 * throwing those errors.
	 *
	 * @param array<string, PathReadResult> $readsByPath
	 */
	private function tryCompleteKey(
		RepresentationSource $source,
		RepresentationAdoptionContext $context,
		array $readsByPath,
	): ?Key {
		try {
			return $this->resolveExistingKey($source, $context, $readsByPath, hydrate: true);
		} catch (StateException) {
			return null;
		}
	}

	/**
	 * @param array<string, PathReadResult> $readsByPath
	 */
	private function resolveExistingKey(
		RepresentationSource $source,
		RepresentationAdoptionContext $context,
		array $readsByPath,
		bool $hydrate = false,
	): Key {
		$fromPlace = $this->placeKeyFromReads($source, $readsByPath);
		if ($fromPlace instanceof Key) {
			$external = $context->getIdentities()?->getIdentity(
				$source->getPath(),
				$context->getSourceRow(),
			);
			if ($external instanceof Key) {
				$conflict = $external->conflictingIdentityField($fromPlace->getValues());
				if ($conflict !== null) {
					throw new StateException(sprintf(
						"Cannot bind projection source for collection '%s' because intent identity field '%s' disagrees with the representation.",
						$source->getCollection()->getName(),
						$conflict,
					));
				}
			}

			return $fromPlace;
		}

		$external = $context->getIdentities()?->getIdentity(
			$source->getPath(),
			$context->getSourceRow(),
		);
		if ($external instanceof Key) {
			$conflict = $external->conflictingIdentityField(
				$this->readablePrimaryKeyFromReads($source, $readsByPath),
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
			throw StateException::writableResultMissingIdentity(
				$collection->getName(),
				$collection->getPrimaryKey(),
			);
		}

		throw new StateException(sprintf(
			"Cannot adopt update representation for collection '%s' because its primary key cannot be read through the schema or intent identity.",
			$collection->getName(),
		));
	}

	/**
	 * @param array<string, PathReadResult> $readsByPath
	 */
	private function placeKeyFromReads(RepresentationSource $source, array $readsByPath): ?Key
	{
		$collection = $source->getCollection();
		$values = [];

		foreach ($collection->getPrimaryKey() as $fieldName) {
			$path = $source->getFieldPath($fieldName);
			if ($path === null) {
				return null;
			}

			$result = $readsByPath[$path] ?? null;
			if (! $result instanceof PathReadResult || ! $result->isPresent()) {
				return null;
			}

			$value = $result->requireValue();
			if ($value === null) {
				return null;
			}

			$values[$fieldName] = $value;
		}

		return $collection->getKey($values);
	}

	/**
	 * @param array<string, PathReadResult> $readsByPath
	 *
	 * @return array<string, mixed>
	 */
	private function readablePrimaryKeyFromReads(RepresentationSource $source, array $readsByPath): array
	{
		$values = [];
		foreach ($source->getCollection()->getPrimaryKey() as $fieldName) {
			$path = $source->getFieldPath($fieldName);
			if ($path === null) {
				continue;
			}

			$result = $readsByPath[$path] ?? null;
			if ($result instanceof PathReadResult && $result->isPresent()) {
				$values[$fieldName] = $result->requireValue();
			}
		}

		return $values;
	}

	/**
	 * @param array<string, mixed>|null $sourceRow
	 */
	private function readPlaceValue(object $representation, string $path, ?array $sourceRow): PathReadResult
	{
		if ($sourceRow !== null && array_key_exists($path, $sourceRow)) {
			return PathReadResult::present($sourceRow[$path]);
		}

		return $this->reader->readPath($representation, $path);
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

	/**
	 * @param non-empty-string $message
	 */
	private function relationError(string $message): StateException
	{
		return new StateException(rtrim($message, '.') . ' during graph adoption.');
	}
}
