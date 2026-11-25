<?php

declare(strict_types=1);

namespace ON\Data\ORM\Representation\Sync;

use ON\Data\Key;
use ON\Data\ORM\Exception\StateException;
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
		RepresentationAdoptionContext $context,
		SessionContext $session,
	): RepresentationState {
		$visited = [];

		return $this->attachObject($representation, $context, $session, $visited, $session->getIntents()->get($representation));
	}

	/** @param array<int, true> $visited */
	private function attachObject(
		object $representation,
		RepresentationAdoptionContext $context,
		SessionContext $session,
		array &$visited,
		?RepresentationIntent $storedIntent,
	): RepresentationState {
		$id = spl_object_id($representation);
		$representations = $session->getRepresentations();
		$tracked = $representations->get($representation);
		if (isset($visited[$id])) {
			return $tracked ?? throw new StateException('Visited representation is missing tracked state.');
		}
		$visited[$id] = true;

		$intent = $context->getIntent();
		$replace = $intent !== null && ($intent->getFlatOps() !== [] || $intent->getSchema() !== null);
		if ($tracked === null || $replace) {
			$rootPolicy = $this->rootPolicy($context, $storedIntent);
			$state = $this->bindSources($representation, $context, $rootPolicy, $session);
			foreach ($state->getUniqueRecords() as $record) {
				$session->getRecords()->add($record);
			}
			if ($replace) {
				$representations->remove($representation);
			}
			$representations->add($representation, $state);
		} else {
			$state = $tracked;
		}

		$schema = $state->getSchema();
		$this->assertRelationOwners($schema);
		foreach ($schema->getRelations() as $relation) {
			foreach ($this->reader->readRelatedObjects($representation, $relation, $this->relationError(...)) as $child) {
				$childIntent = $session->getIntents()->get($child);
				$childSchema = $childIntent?->getSchema() ?? $relation->getRelatedSchema();
				$this->attachObject(
					$child,
					new RepresentationAdoptionContext(
						schema: $childSchema,
						policy: AdoptionPolicy::Create,
						identities: $childIntent === null ? null : StaticSourceIdentities::fromIntent($childSchema, $childIntent),
						intent: $childIntent,
					),
					$session,
					$visited,
					$childIntent,
				);
			}
		}

		if ($intent !== null) {
			$session->getIntents()->remove($representation);
		}

		return $state;
	}

	private function bindSources(
		object $representation,
		RepresentationAdoptionContext $context,
		AdoptionPolicy $rootPolicy,
		SessionContext $session,
	): RepresentationState {
		$schema = $context->getSchema();
		$sources = $schema->getSources();
		if ($sources === [] && $schema->getRelations() === []) {
			throw new StateException(
				'Cannot synchronize untracked root representation because untracked root sync needs a schema targeting one collection.',
			);
		}
		$this->assertRelationOwners($schema);

		$ops = [];
		foreach ($context->getIntent()?->getFlatOps() ?? [] as $op) {
			$ops[$op->getPath()] = $op;
		}

		$records = $session->getRecords();
		$bound = [];
		$flatCreates = [];
		if ($sources === []) {
			$bound[''] = $this->bindEmptyRoot($context, $records);
		}

		$readPath = $this->reader->readPass($representation);
		foreach ($sources as $source) {
			$this->assertSourceFieldCollections($source);
			$path = $source->getPathKey();
			$op = $ops[$path] ?? null;
			$policy = $this->sourcePolicy($source, $op, $rootPolicy, $context->getPolicy());
			[$reads, $values] = RepresentationSourceReader::read($source, $readPath);
			$bound[$path] = $this->bindSource($source, $policy, $reads, $values, $context->getIdentities()?->getIdentity($path), $records);
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

	private function bindEmptyRoot(RepresentationAdoptionContext $context, RecordStateStore $records): RecordState
	{
		$identity = $context->getIdentities()?->getIdentity('');
		if ($context->getPolicy() === AdoptionPolicy::Patch && $identity !== null) {
			return $records->bindExisting(
				$identity,
				[],
				sprintf("Cannot bind projection source '[root]' because key '%s' is already tracked as removed.", $identity->getDebugString()),
			);
		}

		return RecordState::new($context->getSchema()->getCollection());
	}

	private function rootPolicy(RepresentationAdoptionContext $context, ?RepresentationIntent $storedIntent): AdoptionPolicy
	{
		if ($storedIntent !== null) {
			return $storedIntent->isCreate() ? AdoptionPolicy::Create : AdoptionPolicy::Patch;
		}

		return $context->getIntent()?->isCreate() === true ? AdoptionPolicy::Create : $context->getPolicy();
	}

	private function sourcePolicy(
		RepresentationSource $source,
		?FlatIntentOp $op,
		AdoptionPolicy $root,
		AdoptionPolicy $related,
	): AdoptionPolicy {
		if ($op !== null) {
			return $op->isCreate() ? AdoptionPolicy::Create : AdoptionPolicy::Patch;
		}

		return $source->isRoot() ? $root : $related;
	}

	/**
	 * @param array<string, PathReadResult<mixed>> $reads
	 * @param array<string, mixed> $values
	 */
	private function bindSource(
		RepresentationSource $source,
		AdoptionPolicy $policy,
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

		if ($policy === AdoptionPolicy::Create) {
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
