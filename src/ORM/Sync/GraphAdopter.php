<?php

declare(strict_types=1);

namespace ON\Data\ORM\Sync;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\State\RecordStateStore;
use ON\Data\ORM\State\RepresentationBinding;
use ON\Data\ORM\State\RepresentationState;
use ON\Data\ORM\State\RepresentationStore;

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
		RepresentationStore $representations,
		RecordStateStore $records,
		?RepresentationBinding $rootBinding = null,
		?CollectionInterface $rootCollection = null,
		?array $sourceRow = null,
	): array {
		if ($representations->get($root) === null) {
			if (! $rootBinding instanceof RepresentationBinding) {
				throw new StateException('Cannot adopt representation graph because the root representation is not tracked and no root binding was provided.');
			}

			$adopter = new RepresentationAdopter($records, $representations);

			if ($this->usesProjectionBinding($rootBinding, $rootCollection)) {
				assert($rootCollection instanceof CollectionInterface);
				$adopter->adoptWithRecords(
					$root,
					$rootBinding,
					$this->records->resolveAll($root, $rootBinding, $records, $rootCollection, $sourceRow),
					$rootCollection,
				);
			} else {
				$adopter->adopt(
					$root,
					$rootBinding,
					$this->records->resolve($root, $rootBinding, $records, true, $sourceRow, $rootCollection),
				);
			}
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
		RepresentationStore $representations,
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
		foreach ($tracked->getBinding()->getRelations() as $relationBinding) {
			if ($relationBinding->isMany()) {
				foreach ($this->reader->readItems($representation, $relationBinding, $this->adoptionError(...)) as $item) {
					$this->adoptAndWalk($item, $relationBinding->getRelatedBinding(), $representations, $records, $adopter, $visited, $adopted);
				}

				continue;
			}

			if ($relationBinding->isSingle()) {
				$target = $this->reader->readTarget($representation, $relationBinding, $this->adoptionError(...));
				if ($target !== null) {
					$this->adoptAndWalk($target, $relationBinding->getRelatedBinding(), $representations, $records, $adopter, $visited, $adopted);
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
		RepresentationBinding $binding,
		RepresentationStore $representations,
		RecordStateStore $records,
		RepresentationAdopter $adopter,
		array &$visited,
		array &$adopted,
	): void {
		if (! $representations->has($representation)) {
			$adopted[] = $adopter->adopt(
				$representation,
				$binding,
				$this->records->resolve($representation, $binding, $records, false, null, null),
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

	private function usesProjectionBinding(
		RepresentationBinding $binding,
		?CollectionInterface $rootCollection,
	): bool {
		if (! $rootCollection instanceof CollectionInterface) {
			return false;
		}

		foreach ($binding->getFields() as $fieldBinding) {
			if ($fieldBinding->getField()->getCollectionName() !== $rootCollection->getName()) {
				return true;
			}
		}

		return false;
	}
}
