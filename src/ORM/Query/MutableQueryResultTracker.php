<?php

declare(strict_types=1);

namespace ON\Data\ORM\Query;

use ON\Data\ORM\Session;
use ON\Data\ORM\State\RepresentationBinding;

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
