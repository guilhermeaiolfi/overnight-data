<?php

declare(strict_types=1);

namespace ON\Data\ORM\Sync;

use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\State\RecordStateStore;
use ON\Data\ORM\State\RepresentationSchema;
use ON\Data\ORM\State\RepresentationState;
use ON\Data\ORM\State\RepresentationStateStore;

final class GraphAdopter
{
	private RepresentationReader $reader;
	private AdoptionRecordResolver $records;

	public function __construct(
		?RepresentationReader $reader = null,
		?AdoptionRecordResolver $records = null,
	) {
		$this->reader = $reader ?? new RepresentationReader();
		$this->records = $records ?? new AdoptionRecordResolver($this->reader);
	}

	/**
	 * @return list<RepresentationState>
	 */
	public function adopt(
		object $root,
		RepresentationStateStore $representations,
		RecordStateStore $records,
		?RepresentationSchema $rootSchema = null,
	): array {
		if ($representations->get($root) === null) {
			if (! $rootSchema instanceof RepresentationSchema) {
				throw new StateException('Cannot adopt representation graph because the root representation is not tracked and no root binding was provided.');
			}

			(new RepresentationAdopter($records, $representations))->adopt(
				$root,
				$rootSchema,
				$this->records->resolve($root, $rootSchema, $records, true)
			);
		}

		$adopter = new RepresentationAdopter($records, $representations);
		$visited = [];
		$adopted = [];

		$this->walk($root, $representations, $records, $adopter, $visited, $adopted);

		return $adopted;
	}

	/**
	 * @param array<int, true> $visited
	 * @param list<RepresentationState> $adopted
	 */
	private function walk(
		object $representation,
		RepresentationStateStore $representations,
		RecordStateStore $records,
		RepresentationAdopter $adopter,
		array &$visited,
		array &$adopted,
	): void {
		$id = spl_object_id($representation);
		if (array_key_exists($id, $visited)) {
			return;
		}

		$tracked = $representations->get($representation);
		if ($tracked === null) {
			throw new StateException('Cannot walk representation graph because a representation is not tracked.');
		}

		$visited[$id] = true;
		foreach ($tracked->getSchema()->getRelations() as $relationSchema) {
			if ($relationSchema->isMany()) {
				foreach ($this->reader->readItems($representation, $relationSchema, $this->adoptionError(...)) as $item) {
					$this->adoptAndWalk($item, $relationSchema->getRelatedSchema(), $representations, $records, $adopter, $visited, $adopted);
				}

				continue;
			}

			if ($relationSchema->isSingle()) {
				$target = $this->reader->readTarget($representation, $relationSchema, $this->adoptionError(...));
				if ($target !== null) {
					$this->adoptAndWalk($target, $relationSchema->getRelatedSchema(), $representations, $records, $adopter, $visited, $adopted);
				}
			}
		}
	}

	/**
	 * @param array<int, true> $visited
	 * @param list<RepresentationState> $adopted
	 */
	private function adoptAndWalk(
		object $representation,
		RepresentationSchema $binding,
		RepresentationStateStore $representations,
		RecordStateStore $records,
		RepresentationAdopter $adopter,
		array &$visited,
		array &$adopted,
	): void {
		if (! $representations->has($representation)) {
			$adopted[] = $adopter->adopt(
				$representation,
				$binding,
				$this->records->resolve($representation, $binding, $records, false)
			);
		}

		$this->walk($representation, $representations, $records, $adopter, $visited, $adopted);
	}

	/**
	 * @param non-empty-string $message
	 */
	private function adoptionError(string $message): StateException
	{
		return new StateException(rtrim($message, '.') . ' during graph adoption.');
	}
}
