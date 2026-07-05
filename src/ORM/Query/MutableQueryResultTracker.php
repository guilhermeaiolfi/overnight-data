<?php

declare(strict_types=1);

namespace ON\Data\ORM\Query;

use ON\Data\ORM\Session;
use ON\Data\ORM\State\RepresentationBinding;
use ON\Data\Query\SelectQuery;

final class MutableQueryResultTracker
{
	public function __construct(
		private ?ProjectionRepresentationAdopter $projectionAdopter = null,
	) {
		$this->projectionAdopter ??= new ProjectionRepresentationAdopter();
	}

	/**
	 * @param list<object> $objects
	 * @param list<array<string, mixed>> $sourceRows
	 */
	public function trackAll(
		SelectQuery $query,
		Session $session,
		RepresentationBinding $binding,
		array $objects,
		array $sourceRows,
	): void {
		foreach ($objects as $index => $object) {
			$this->trackObject($query, $session, $object, $binding, $sourceRows[$index] ?? []);
		}
	}

	/**
	 * @param array<string, mixed> $sourceRow
	 */
	public function trackOne(
		SelectQuery $query,
		Session $session,
		RepresentationBinding $binding,
		object $object,
		array $sourceRow,
	): void {
		$this->trackObject($query, $session, $object, $binding, $sourceRow);
	}

	/**
	 * @param array<string, mixed> $sourceRow
	 */
	private function trackObject(
		SelectQuery $query,
		Session $session,
		object $object,
		RepresentationBinding $binding,
		array $sourceRow,
	): void {
		if ($this->isProjectionBinding($binding)) {
			$this->projectionAdopter->adopt($object, $binding, $query, $sourceRow, $session->getContext());
			$session->sync($object);

			return;
		}

		$session->sync($object, $binding);
	}

	private function isProjectionBinding(RepresentationBinding $binding): bool
	{
		$collections = [];

		foreach ($binding->getFields() as $fieldBinding) {
			$collections[$fieldBinding->getField()->getCollectionName()] = true;
		}

		return count($collections) > 1;
	}
}
