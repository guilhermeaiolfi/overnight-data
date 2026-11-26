<?php

declare(strict_types=1);

namespace ON\Data\ORM\Representation\Sync;

use ON\Data\Key;
use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\Exception\SyncException;
use ON\Data\ORM\Record\RecordState;
use ON\Data\ORM\Record\RecordStateStore;
use ON\Data\ORM\Relation\RelationStateStore;
use ON\Data\ORM\Relation\ToManyRelationState;
use ON\Data\ORM\Representation\State\RepresentationState;
use ON\Data\ORM\SessionContext;
use ON\Data\Schema\RepresentationSchema;
use ON\Data\Schema\RepresentationSource;

/** Binds inbound objects and staged write intents before synchronization. */
final class RepresentationAdoptionEngine
{
	public function __construct(private RepresentationReader $reader = new RepresentationReader())
	{
	}

	public function attach(
		object $representation,
		?RepresentationSchema $schema,
		SessionContext $session,
	): RepresentationState {
		$intent = $session->getIntents()->get($representation);
		$schema ??= $intent?->getSchema() ?? $session->getRepresentations()->get($representation)?->getSchema()
			?? throw new SyncException('Cannot synchronize an untracked representation object without a root RepresentationSchema.');
		$visited = [];

		return $this->attachObject(
			$representation,
			$schema,
			$intent,
			$intent?->getLifecycle() ?? RepresentationIntentLifecycle::Create,
			$session,
			$this->reader->forOperation(),
			$visited,
		);
	}

	/** @param array<int, true> $visited */
	private function attachObject(
		object $representation,
		RepresentationSchema $schema,
		?RepresentationIntent $intent,
		RepresentationIntentLifecycle $relatedPolicy,
		SessionContext $session,
		RepresentationReadOperation $operation,
		array &$visited,
	): RepresentationState {
		$id = spl_object_id($representation);
		$tracked = $session->getRepresentations()->get($representation);
		if (isset($visited[$id])) {
			return $tracked ?? throw new StateException('Visited representation is missing tracked state.');
		}
		$visited[$id] = true;
		$reader = $operation->withObject($representation);

		$replace = $intent !== null && ($intent->getFlatOps() !== [] || $intent->getSchema() !== null);
		if ($tracked === null || ($replace && ! $intent->isBoundTo($tracked))) {
			$state = $this->bindSources($reader, $schema, $intent, $relatedPolicy, $session);
			$session->attachRepresentation($representation, $state, $replace ? RepresentationAttachmentMode::Replace : RepresentationAttachmentMode::Add);
			$intent?->setBoundState($state);
		} else {
			$state = $tracked;
		}

		$schema = $state->getSchema();
		$this->assertRelationOwners($schema);
		foreach ($schema->getRelations() as $relation) {
			foreach ($reader->readRelatedObjects($relation, $this->relationError(...)) as $child) {
				$childIntent = $session->getIntents()->get($child);
				$this->attachObject(
					$child,
					$childIntent?->getSchema() ?? $relation->getRelatedSchema(),
					$childIntent,
					RepresentationIntentLifecycle::Create,
					$session,
					$operation,
					$visited,
				);
			}
		}

		if ($intent !== null) {
			$intent->setBoundState(null);
			$session->getIntents()->remove($representation);
		}

		return $state;
	}

	private function bindSources(
		ObjectRepresentationReader $reader,
		RepresentationSchema $schema,
		?RepresentationIntent $intent,
		RepresentationIntentLifecycle $relatedPolicy,
		SessionContext $session,
	): RepresentationState {
		$sources = $schema->getSources();
		if ($sources === [] && $schema->getRelations() === []) {
			throw new StateException(
				'Cannot synchronize untracked root representation because untracked root sync needs a schema targeting one collection.',
			);
		}
		$this->assertRelationOwners($schema);

		$ops = [];
		foreach ($intent?->getFlatOps() ?? [] as $op) {
			$ops[$op->getPath()] = $op;
		}

		$rootPolicy = $intent?->getLifecycle() ?? RepresentationIntentLifecycle::Create;
		$records = $session->getRecords();
		$bound = [];
		$flatCreates = [];
		if ($sources === []) {
			$bound[''] = RecordState::new($schema->getCollection());
		}

		foreach ($sources as $source) {
			$this->assertSourceFieldCollections($source);
			$path = $source->getPathKey();
			$op = $ops[$path] ?? null;
			$policy = $op?->getLifecycle() ?? ($source->isRoot() ? $rootPolicy : $relatedPolicy);
			$identity = $op?->getKey() ?? ($source->isRoot() ? $intent?->getIdentity() : null);
			$key = $identity === null ? null : $source->getCollection()->getKey($identity);
			[$reads, $values] = RepresentationSourceReader::read($source, $reader->readPath(...));
			$bound[$path] = $this->bindSource($source, $policy, $reads, $values, $key, $records);
			if (! $source->isRoot() && $op?->isCreate() === true) {
				$flatCreates[] = $source;
			}
		}

		if (isset($bound[''])) {
			foreach ($flatCreates as $source) {
				$this->registerRelationAdd($bound[''], $source, $bound[$source->getPathKey()], $session->getRelations());
			}
		}

		return RepresentationState::fromRecords($schema, $bound);
	}

	/**
	 * @param array<string, PathReadResult<mixed>> $reads
	 * @param array<string, mixed> $values
	 */
	private function bindSource(
		RepresentationSource $source,
		RepresentationIntentLifecycle $policy,
		array $reads,
		array $values,
		?Key $identity,
		RecordStateStore $records,
	): RecordState {
		$collection = $source->getCollection();
		foreach ($collection->getPrimaryKey() as $field) {
			if (! isset($values[$field])) {
				unset($values[$field]);
			}
		}

		if ($policy === RepresentationIntentLifecycle::Create) {
			try {
				$key = RepresentationSourceReader::resolveKey($source, $reads, $identity);
			} catch (StateException) {
				// Create uses an assigned key only to reuse an already tracked record.
				$key = null;
			}
			if ($key !== null) {
				$existing = $records->getActive($key, sprintf(
					"Cannot adopt representation for collection '%s' because key '%s' is already tracked as removed.",
					$collection->getName(),
					$key->getDebugString(),
				));
				if ($existing !== null) {
					return $existing;
				}
			}

			$record = RecordState::new($collection, $values);
			$records->add($record);

			return $record;
		}

		$key = RepresentationSourceReader::resolveKey($source, $reads, $identity)
			?? throw new StateException(sprintf(
				"Cannot adopt update representation for collection '%s' because its primary key cannot be read through the schema or intent identity.",
				$collection->getName(),
			));

		return $records->bindExisting($key, $values, sprintf(
			"Cannot bind projection source '%s' because key '%s' is already tracked as removed.",
			$source->isRoot() ? '[root]' : $source->getPathKey(),
			$key->getDebugString(),
		));
	}

	private function assertRelationOwners(RepresentationSchema $schema): void
	{
		foreach ($schema->getRelations() as $relation) {
			$this->assertCollection($relation->getPath(), $relation->getOwnerCollectionName(), $schema->getCollectionName());
		}
	}

	private function assertSourceFieldCollections(RepresentationSource $source): void
	{
		foreach ($source->getFields() as $field) {
			$this->assertCollection($field->getPath(), $field->getCollectionName(), $source->getCollection()->getName());
		}
	}

	private function assertCollection(string $path, string $collection, string $expected): void
	{
		if ($collection !== $expected) {
			throw new StateException(sprintf(
				"Cannot synchronize untracked root representation because untracked root sync needs a schema targeting one collection; path '%s' targets collection '%s' after '%s'.",
				$path,
				$collection,
				$expected,
			));
		}
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
		$state = $relations->getOrCreate(
			$owner,
			$relationName,
			$definition->getCardinality(),
			$relatedSchema,
		);

		if ($state instanceof ToManyRelationState) {
			$state->add($relatedRecord);

			return;
		}

		$state->set($relatedRecord);
	}

	/**
	 * @param non-empty-string $message
	 */
	private function relationError(string $message): StateException
	{
		return new StateException(rtrim($message, '.') . ' during graph adoption.');
	}
}
