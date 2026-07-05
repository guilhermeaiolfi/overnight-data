<?php

declare(strict_types=1);

namespace ON\Data\ORM\Query;

use ON\Data\ORM\Binding\SelectQueryBindingCompiler;
use ON\Data\ORM\Session;
use ON\Data\ORM\State\RepresentationBinding;
use ON\Data\Query\SelectQuery;

final class MutableQueryResultTracker
{
	public function __construct(
		private ?SelectQueryBindingCompiler $compiler = null,
		private ?ProjectionRepresentationAdopter $projectionAdopter = null,
	) {
		$this->compiler ??= new SelectQueryBindingCompiler();
		$this->projectionAdopter ??= new ProjectionRepresentationAdopter();
	}

	/**
	 * @param list<object> $objects
	 * @param list<array<string, mixed>> $sourceRows
	 */
	public function trackAll(
		SelectQuery $query,
		Session $session,
		array $objects,
		array $sourceRows,
	): RepresentationBinding {
		$binding = $this->compiler->compile($query);

		foreach ($objects as $index => $object) {
			$this->trackObject($query, $session, $object, $binding, $sourceRows[$index] ?? []);
		}

		return $binding;
	}

	/**
	 * @param array<string, mixed> $sourceRow
	 */
	public function trackOne(
		SelectQuery $query,
		Session $session,
		object $object,
		array $sourceRow,
	): RepresentationBinding {
		$binding = $this->compiler->compile($query);
		$this->trackObject($query, $session, $object, $binding, $sourceRow);

		return $binding;
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
