<?php

declare(strict_types=1);

namespace ON\Data\ORM\Query;

/**
 * Routes mutable query results to the correct adoption path (flat projection vs
 * entity graph) and triggers session sync.
 *
 * Exists as the bridge between SelectQuery mutable export and Session tracking,
 * keeping SelectQuery itself free of persistence orchestration details.
 */
use ON\Data\ORM\Compiler\SelectQuery\ProjectionIdentityMap;
use ON\Data\ORM\Session;
use ON\Data\ORM\State\RepresentationBinding;
use ON\Data\ORM\Sync\RepresentationReader;
use RuntimeException;

final class MutableQueryResultTracker
{
	private RepresentationReader $reader;

	public function __construct(
		private ?ProjectionRepresentationAdopter $projectionAdopter = null,
		?RepresentationReader $reader = null,
	) {
		$this->projectionAdopter ??= new ProjectionRepresentationAdopter();
		$this->reader = $reader ?? new RepresentationReader();
	}

	/**
	 * @param list<object> $objects
	 * @param list<array<string, mixed>> $sourceRows
	 */
	public function trackAll(
		Session $session,
		RepresentationBinding $binding,
		ProjectionIdentityMap $projectionIdentities,
		array $objects,
		array $sourceRows,
	): void {
		foreach ($objects as $index => $object) {
			$this->trackObject($session, $object, $binding, $projectionIdentities, $sourceRows[$index] ?? []);
		}
	}

	/**
	 * @param array<string, mixed> $sourceRow
	 */
	public function trackOne(
		Session $session,
		RepresentationBinding $binding,
		ProjectionIdentityMap $projectionIdentities,
		object $object,
		array $sourceRow,
	): void {
		$this->trackObject($session, $object, $binding, $projectionIdentities, $sourceRow);
	}

	/**
	 * @param array<string, mixed> $sourceRow
	 */
	private function trackObject(
		Session $session,
		object $object,
		RepresentationBinding $binding,
		ProjectionIdentityMap $projectionIdentities,
		array $sourceRow,
	): void {
		if ($this->isProjectionBinding($binding)) {
			$this->projectionAdopter->adopt(
				$object,
				$binding,
				$projectionIdentities,
				$sourceRow,
				$session->getContext(),
			);
			$session->sync($object);

			return;
		}

		$this->markLoadedRelatedObjectsExisting($session, $object, $binding);
		$session->sync($object, $binding);
	}

	private function isProjectionBinding(RepresentationBinding $binding): bool
	{
		$sourcePaths = [];

		foreach ($binding->getFields() as $fieldBinding) {
			$sourcePaths[$fieldBinding->getSourcePathKey()] = true;
		}

		return count($sourcePaths) > 1;
	}

	private function markLoadedRelatedObjectsExisting(
		Session $session,
		object $object,
		RepresentationBinding $binding,
	): void {
		foreach ($binding->getRelations() as $relation) {
			if ($relation->isMany()) {
				foreach ($this->reader->readItems($object, $relation, static fn (string $message) => new RuntimeException($message)) as $item) {
					$session->existing($item);
					$this->markLoadedRelatedObjectsExisting($session, $item, $relation->getRelatedBinding());
				}

				continue;
			}

			if ($relation->isSingle()) {
				$target = $this->reader->readTarget($object, $relation, static fn (string $message) => new RuntimeException($message));
				if ($target !== null) {
					$session->existing($target);
					$this->markLoadedRelatedObjectsExisting($session, $target, $relation->getRelatedBinding());
				}
			}
		}
	}
}
