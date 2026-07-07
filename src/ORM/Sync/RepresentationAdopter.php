<?php

declare(strict_types=1);

namespace ON\Data\ORM\Sync;

use ON\Data\ORM\Exception\SyncException;
use ON\Data\ORM\State\RecordState;
use ON\Data\ORM\State\RecordStateStore;
use ON\Data\ORM\State\RepresentationBinding;
use ON\Data\ORM\State\RepresentationFieldStateItem;
use ON\Data\ORM\State\RepresentationRelationStateItem;
use ON\Data\ORM\State\RepresentationState;
use ON\Data\ORM\State\RepresentationStore;

final class RepresentationAdopter
{
	public function __construct(
		private RecordStateStore $records,
		private RepresentationStore $representations,
	) {
	}

	public function adopt(
		object $representation,
		RepresentationBinding $binding,
		RecordState $record,
	): RepresentationState {
		if ($this->representations->has($representation)) {
			throw new SyncException('Cannot adopt representation because it is already tracked.');
		}

		$state = new RepresentationState(
			$binding,
			$this->buildFieldItems($binding, $record),
			$this->buildRelationItems($binding, $record),
		);

		$this->records->add($record);
		$this->representations->add($representation, $state);

		return $state;
	}

	/**
	 * @return list<RepresentationFieldStateItem>
	 */
	private function buildFieldItems(RepresentationBinding $binding, RecordState $record): array
	{
		$items = [];
		foreach ($binding->getFields() as $fieldBinding) {
			if ($fieldBinding->getCollectionName() !== $record->getCollection()->getName()) {
				throw new SyncException(sprintf(
					"Representation binding path '%s' targets collection '%s', not '%s'.",
					$fieldBinding->getPath(),
					$fieldBinding->getCollectionName(),
					$record->getCollection()->getName()
				));
			}

			$items[] = new RepresentationFieldStateItem(
				$fieldBinding,
				$record,
				$fieldBinding->getFieldName(),
				$record->getRevision()
			);
		}

		return $items;
	}

	/**
	 * @return list<RepresentationRelationStateItem>
	 */
	private function buildRelationItems(RepresentationBinding $binding, RecordState $record): array
	{
		$items = [];
		foreach ($binding->getRelations() as $relationBinding) {
			if ($relationBinding->getOwnerCollectionName() !== $record->getCollection()->getName()) {
				throw new SyncException(sprintf(
					"Representation relation path '%s' targets collection '%s', not '%s'.",
					$relationBinding->getPath(),
					$relationBinding->getOwnerCollectionName(),
					$record->getCollection()->getName()
				));
			}

			$items[] = new RepresentationRelationStateItem(
				$relationBinding,
				$record,
				$relationBinding->getRelationName()
			);
		}

		return $items;
	}
}
