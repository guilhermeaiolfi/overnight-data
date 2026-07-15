<?php

declare(strict_types=1);

namespace ON\Data\ORM\Representation\State\Query;

use ON\Data\ORM\Exception\SyncException;
use ON\Data\ORM\Representation\Schema\Query\QueryRepresentationPlan;
use ON\Data\ORM\Session;
use ON\Data\ORM\Representation\Schema\RepresentationSchema;
use ON\Data\ORM\Representation\Sync\RepresentationReader;
use RuntimeException;
/**
 * Routes mutable query results to the correct adoption path (flat projection vs
 * entity graph) and triggers session sync.
 *
 * Exists as the bridge between SelectQuery mutable export and Session tracking,
 * keeping SelectQuery itself free of persistence orchestration details.
 */
final class MutableQueryResultTracker
{
	private RepresentationReader $reader;

	public function __construct(
		private ?QueryRepresentationStateBuilder $stateBuilder = null,
		?RepresentationReader $reader = null,
	) {
		$this->stateBuilder ??= new QueryRepresentationStateBuilder();
		$this->reader = $reader ?? new RepresentationReader();
	}

	/**
	 * @param list<object> $objects
	 * @param list<array<string, mixed>> $sourceRows
	 */
	public function trackAll(
		Session $session,
		QueryRepresentationPlan $compilation,
		array $objects,
		array $sourceRows,
	): void {
		foreach ($objects as $index => $object) {
			$this->trackObject($session, $object, $compilation, $sourceRows[$index] ?? []);
		}
	}

	/**
	 * @param array<string, mixed> $sourceRow
	 */
	public function trackOne(
		Session $session,
		QueryRepresentationPlan $compilation,
		object $object,
		array $sourceRow,
	): void {
		$this->trackObject($session, $object, $compilation, $sourceRow);
	}

	/**
	 * @param array<string, mixed> $sourceRow
	 */
	private function trackObject(
		Session $session,
		object $object,
		QueryRepresentationPlan $compilation,
		array $sourceRow,
	): void {
		if ($compilation->hasNonRootSources()) {
			$state = $this->stateBuilder->build(
				$object,
				$compilation,
				$sourceRow,
				$session->getRecords(),
			);
			$session->adopt($object, $state);
			$session->sync($object);

			return;
		}

		$schema = $compilation->getSchema();

		if ($this->hasReadableRootPrimaryKey($object, $schema)) {
			$session->existing($object);
		}
		$this->markLoadedRelatedObjectsExisting($session, $object, $schema);
		$session->sync($object, $schema);
	}

	private function hasReadableRootPrimaryKey(object $representation, RepresentationSchema $schema): bool
	{
		$collection = $schema->getCollection();
		$pathsByField = [];

		foreach ($schema->getFields() as $fieldSchema) {
			if ($fieldSchema->getCollectionName() === $collection->getName()) {
				$pathsByField[$fieldSchema->getFieldName()] = $fieldSchema->getPath();
			}
		}

		foreach ($collection->getPrimaryKey() as $fieldName) {
			if (! array_key_exists($fieldName, $pathsByField)) {
				return false;
			}

			try {
				$value = $this->reader->readPath($representation, $pathsByField[$fieldName]);
			} catch (SyncException) {
				return false;
			}

			if ($value === null) {
				return false;
			}
		}

		return true;
	}

	private function markLoadedRelatedObjectsExisting(
		Session $session,
		object $object,
		RepresentationSchema $schema,
	): void {
		foreach ($schema->getRelations() as $relation) {
			if ($relation->isMany()) {
				foreach ($this->reader->readItems($object, $relation, static fn (string $message) => new RuntimeException($message)) as $item) {
					$session->existing($item);
					$this->markLoadedRelatedObjectsExisting($session, $item, $relation->getRelatedSchema());
				}

				continue;
			}

			if ($relation->isSingle()) {
				$target = $this->reader->readTarget($object, $relation, static fn (string $message) => new RuntimeException($message));
				if ($target !== null) {
					$session->existing($target);
					$this->markLoadedRelatedObjectsExisting($session, $target, $relation->getRelatedSchema());
				}
			}
		}
	}
}
