<?php

declare(strict_types=1);

namespace ON\Data\ORM\Representation\Schema\Manual;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Relation\RelationCardinality;
use ON\Data\Key;
use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\Exception\SyncException;
use ON\Data\ORM\Record\RecordState;
use ON\Data\ORM\Relation\ToManyRelationState;
use ON\Data\ORM\Relation\ToOneRelationState;
use ON\Data\ORM\Representation\Schema\RepresentationFieldSchema;
use ON\Data\ORM\Representation\Schema\RepresentationSchema;
use ON\Data\ORM\Representation\State\RepresentationFieldStateItem;
use ON\Data\ORM\Representation\State\RepresentationState;
use ON\Data\ORM\Session;
use stdClass;

/**
 * Creates and attaches manual representation sources for create(), existing(), and
 * tracked() without duplicating branch logic in Builder.
 *
 * Exists because Builder's lifecycle methods share the same root/path/relation
 * resolution paths; this class owns record resolution and relation attachment.
 */
final class ManualRepresentationSourceFactory
{
	public function __construct(
		private Session $session,
		private object $rootRepresentation,
		private PathResolver $pathResolver,
	) {
	}

	public function resolvePath(object $owner, string $path): PathResolution
	{
		return $this->pathResolver->resolve($owner, $path);
	}

	/**
	 * @param array<string, mixed> $values
	 */
	public function createRoot(CollectionInterface $collection, array $values): RootRepresentationSource
	{
		return $this->rootSource($this->newRecord($collection, $values));
	}

	/**
	 * @param array<string, mixed> $values
	 */
	public function createAtPath(PathResolution $path, array $values): RelationRepresentationSource
	{
		return $this->pathSource(
			$path,
			$this->newRecord($path->getRelatedSchema()->getCollection(), $values),
		);
	}

	/**
	 * @param array<string, mixed> $values
	 */
	public function createAtRelation(RelationRef $relation, array $values): RelationRepresentationSource
	{
		$record = $this->newRecord($relation->getDefinition()->getCollection(), $values);
		$identityObject = $this->createIdentityObject($record);

		return $this->relationSource(
			$relation,
			$record,
			$identityObject,
			[new PendingRepresentationAdoption($identityObject, $this->fromPrimaryKeyRecord($record))],
		);
	}

	public function existingRoot(CollectionInterface $collection, Key|array $key, array $seedValues): RootRepresentationSource
	{
		return $this->rootSource($this->existingRecord($collection, $key, $seedValues));
	}

	public function existingAtPath(PathResolution $path, Key|array $key, array $seedValues): RelationRepresentationSource
	{
		return $this->pathSource(
			$path,
			$this->existingRecord($path->getRelatedSchema()->getCollection(), $key, $seedValues),
		);
	}

	public function existingAtRelation(RelationRef $relation, Key|array $key, array $seedValues): RelationRepresentationSource
	{
		$record = $this->existingRecord($relation->getDefinition()->getCollection(), $key, $seedValues);
		$identityObject = $this->createIdentityObject($record);

		return $this->relationSource(
			$relation,
			$record,
			$identityObject,
			[new PendingRepresentationAdoption($identityObject, $this->fromPrimaryKeyRecord($record))],
		);
	}

	public function trackedRoot(object $representation, CollectionInterface $collection): RootRepresentationSource
	{
		return $this->rootSource($this->trackedRecord(
			$representation,
			$collection,
			sprintf("Cannot use tracked() for collection '%s'", $collection->getName()),
		));
	}

	public function trackedAtPath(PathResolution $path, object $representation, ?object $target): RelationRepresentationSource
	{
		$target ??= $representation;

		return $this->pathSource(
			$path,
			$this->trackedRecord(
				$target,
				$path->getRelatedSchema()->getCollection(),
				sprintf("Cannot use tracked() for relation '%s'", $path->getRelationName()),
			),
			$target,
		);
	}

	public function trackedAtRelation(RelationRef $relation, object $representation, ?object $target): RelationRepresentationSource
	{
		$target ??= $representation;

		return $this->relationSource(
			$relation,
			$this->trackedRecord(
				$target,
				$relation->getDefinition()->getCollection(),
				sprintf("Cannot use tracked() for relation '%s'", implode('.', $relation->getPath())),
			),
			$target,
		);
	}

	private function rootSource(RecordState $record): RootRepresentationSource
	{
		return new RootRepresentationSource($record);
	}

	private function pathSource(
		PathResolution $path,
		RecordState $record,
		?object $explicitTarget = null,
	): RelationRepresentationSource {
		$objectShaped = $this->rootRepresentation !== $path->getOwnerObject();
		[$target, $pendingEnrollments] = $this->resolvePathTargetObject(
			$record,
			$path->getRelatedSchema(),
			$path->getOwnerObject(),
			$explicitTarget,
			$objectShaped,
		);

		return $this->attachRelationTarget(
			$path->getOwner(),
			$path->getRelationName(),
			$path->getCardinality(),
			$path->getRelatedSchema(),
			$record,
			$target,
			$objectShaped,
			$pendingEnrollments,
		);
	}

	/**
	 * @param list<PendingRepresentationAdoption> $pendingEnrollments
	 */
	private function relationSource(
		RelationRef $relation,
		RecordState $record,
		?object $target = null,
		array $pendingEnrollments = [],
	): RelationRepresentationSource {
		$relatedSchema = new RepresentationSchema($relation->getDefinition()->getCollection());

		return $this->attachRelationTarget(
			$relation->getOwner()->getTargetRecord(),
			$relation->getName(),
			$this->relationCardinality($relation),
			$relatedSchema,
			$record,
			$target ?? $this->rootRepresentation,
			false,
			$pendingEnrollments,
		);
	}

	/**
	 * @param list<PendingRepresentationAdoption> $pendingEnrollments
	 */
	private function attachRelationTarget(
		RecordState $owner,
		string $relationName,
		RelationCardinality $cardinality,
		RepresentationSchema $relatedSchema,
		RecordState $record,
		object $target,
		bool $objectShaped,
		array $pendingEnrollments = [],
	): RelationRepresentationSource {
		$this->applyRelationTarget($owner, $relationName, $cardinality, $relatedSchema, $target);

		return new RelationRepresentationSource(
			$owner,
			$relationName,
			$cardinality,
			$relatedSchema,
			$record,
			$target,
			$objectShaped,
			$pendingEnrollments,
		);
	}

	private function applyRelationTarget(
		RecordState $owner,
		string $relationName,
		RelationCardinality $cardinality,
		RepresentationSchema $relatedSchema,
		object $target,
	): void {
		$relations = $this->session->getRelations();
		$relation = $relations->get($owner, $relationName);

		if ($cardinality->isMany()) {
			if ($relation === null) {
				$relation = new ToManyRelationState($owner, $relationName, $relatedSchema);
				$relations->add($relation);
			} elseif (! $relation instanceof ToManyRelationState) {
				throw $this->incompatibleCardinality($relationName);
			}

			$relation->add($target);

			return;
		}

		if ($relation === null) {
			$relation = new ToOneRelationState($owner, $relationName, $relatedSchema);
			$relations->add($relation);
		} elseif (! $relation instanceof ToOneRelationState) {
			throw $this->incompatibleCardinality($relationName);
		}

		$relation->set($target);
	}

	/**
	 * @return array{0: object, 1: list<PendingRepresentationAdoption>}
	 */
	private function resolvePathTargetObject(
		RecordState $record,
		RepresentationSchema $relatedSchema,
		object $ownerObject,
		?object $explicitTarget,
		bool $objectShaped,
	): array {
		if ($explicitTarget !== null) {
			return [$explicitTarget, $this->pendingEnrollmentsIfUntracked($explicitTarget, $record, $relatedSchema)];
		}

		if ($objectShaped) {
			return [$this->rootRepresentation, $this->pendingEnrollmentsIfUntracked($this->rootRepresentation, $record, $relatedSchema)];
		}

		return $this->resolveFlattenedAdapter($record, $relatedSchema, $ownerObject);
	}

	/**
	 * @param array<string, mixed> $values
	 */
	private function newRecord(CollectionInterface $collection, array $values): RecordState
	{
		$record = RecordState::new($collection, $values);
		$this->session->getRecords()->add($record);

		return $record;
	}

	/**
	 * @param array<string, mixed> $values
	 */
	private function existingRecord(CollectionInterface $collection, Key|array $key, array $values): RecordState
	{
		$key = $collection->getKey($key);
		$record = $this->session->getRecords()->getByKey($key);
		if ($record instanceof RecordState) {
			if ($record->isRemoved()) {
				throw new StateException(sprintf(
					"Cannot identify collection '%s' key '%s' because it is already tracked as removed.",
					$collection->getName(),
					$key->getDebugString()
				));
			}

			return $record;
		}

		$record = RecordState::clean($key, $values + $key->getValues());
		$this->session->getRecords()->add($record);

		return $record;
	}

	private function trackedRecord(object $target, CollectionInterface $collection, string $prefix): RecordState
	{
		$state = $this->session->getRepresentations()->get($target);
		if (! $state instanceof RepresentationState) {
			throw new SyncException($prefix . ' because the target representation is not tracked.');
		}

		$matches = $state->getRecordsForCollection($collection);
		if ($matches === []) {
			throw new StateException($prefix . ' because the target has no matching tracked record state.');
		}

		if (count($matches) > 1) {
			throw new StateException($prefix . ' because the matching target record state is ambiguous.');
		}

		return $matches[0];
	}

	private function relationCardinality(RelationRef $relation): RelationCardinality
	{
		return $relation->getDefinition()->getCardinality();
	}

	private function incompatibleCardinality(string $relationName): StateException
	{
		return new StateException(sprintf(
			"Relation '%s' is already tracked with incompatible cardinality.",
			$relationName
		));
	}

	private function fromPrimaryKeyRecord(RecordState $record): RepresentationState
	{
		$schema = new RepresentationSchema($record->getCollection());
		foreach ($record->getCollection()->getPrimaryKey() as $fieldName) {
			$schema->addField(new RepresentationFieldSchema($fieldName, $record->getCollection(), $fieldName, writable: false));
		}

		return RepresentationState::fromRecords(
			$schema,
			[RepresentationFieldSchema::sourcePathKey([]) => $record],
		);
	}

	private function fromRelationTarget(
		RepresentationSchema $schema,
		RecordState $record,
	): RepresentationState {
		$fieldItems = [];
		foreach ($schema->getFields() as $fieldSchema) {
			$fieldItems[] = RepresentationFieldStateItem::createOne($fieldSchema->withSkipWhenMissing(true), $record);
		}

		return new RepresentationState($schema, $fieldItems);
	}

	private function createIdentityObject(RecordState $record): object
	{
		$object = new stdClass();
		foreach ($record->getCollection()->getPrimaryKey() as $fieldName) {
			if ($record->hasValue($fieldName)) {
				$object->{$fieldName} = $record->getValue($fieldName);
			}
		}

		return $object;
	}

	/**
	 * @return list<PendingRepresentationAdoption>
	 */
	private function pendingEnrollmentsIfUntracked(
		object $target,
		RecordState $record,
		RepresentationSchema $schema,
	): array {
		if ($this->session->getRepresentations()->has($target)) {
			return [];
		}

		return [new PendingRepresentationAdoption($target, $this->fromRelationTarget($schema, $record))];
	}

	/**
	 * @return array{0: object, 1: list<PendingRepresentationAdoption>}
	 */
	private function resolveFlattenedAdapter(
		RecordState $record,
		RepresentationSchema $relatedSchema,
		object $ownerObject,
	): array {
		$target = $ownerObject;
		$state = $this->session->getRepresentations()->get($target);
		if ($state instanceof RepresentationState) {
			$existing = $this->session->getRecords()->getFromRepresentation($state);
			if ($existing !== $record) {
				$target = new stdClass();
			}
		}

		return [$target, $this->pendingEnrollmentsIfUntracked($target, $record, $relatedSchema)];
	}
}
