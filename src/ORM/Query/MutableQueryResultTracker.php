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
use ON\Data\ORM\Compiler\SelectQuery\ProjectionCompilation;
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
		ProjectionCompilation $compilation,
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
		ProjectionCompilation $compilation,
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
		ProjectionCompilation $compilation,
		array $sourceRow,
	): void {
		if ($compilation->hasNonRootSources()) {
			$this->projectionAdopter->adopt(
				$object,
				$compilation,
				$sourceRow,
				$session->getContext(),
			);
			$session->sync($object);

			return;
		}

		$binding = $compilation->getBinding();
		$this->markLoadedRelatedObjectsExisting($session, $object, $binding);
		$session->sync($object, $binding);
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
