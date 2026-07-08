<?php

declare(strict_types=1);

namespace ON\Data\ORM\Sync;

use ON\Data\ORM\Compiler\ProjectionSource;
use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\Exception\SyncException;
use ON\Data\ORM\State\RecordState;
use ON\Data\ORM\State\RepresentationBinding;
use ON\Data\ORM\State\RepresentationFieldStateItem;
use ON\Data\ORM\State\RepresentationRelationStateItem;
use ON\Data\ORM\State\RepresentationState;

final class RepresentationStateFactory
{
	public function fromRootRecord(
		RepresentationBinding $schema,
		RecordState $record,
	): RepresentationState {
		return new RepresentationState(
			$schema,
			$this->fieldItemsForRootRecord($schema, $record),
			$this->relationItemsForRootRecord($schema, $record),
		);
	}

	/**
	 * @param list<ProjectionSource> $sources
	 * @param array<string, RecordState> $recordsBySourceKey
	 */
	public function fromSourceRecords(
		RepresentationBinding $schema,
		array $sources,
		array $recordsBySourceKey,
	): RepresentationState {
		if ($schema->getRelations() !== []) {
			throw new StateException('Cannot create flat projection representation state because the binding contains relation bindings.');
		}

		return new RepresentationState(
			$schema,
			$this->fieldItemsForSourceRecords($sources, $recordsBySourceKey),
		);
	}

	/**
	 * @return list<RepresentationFieldStateItem>
	 */
	private function fieldItemsForRootRecord(RepresentationBinding $schema, RecordState $record): array
	{
		$items = [];
		foreach ($schema->getFields() as $fieldBinding) {
			if ($fieldBinding->getCollectionName() !== $record->getCollection()->getName()) {
				throw new StateException(sprintf(
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
	private function relationItemsForRootRecord(RepresentationBinding $schema, RecordState $record): array
	{
		$items = [];
		foreach ($schema->getRelations() as $relationBinding) {
			if ($relationBinding->getOwnerCollectionName() !== $record->getCollection()->getName()) {
				throw new StateException(sprintf(
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

	/**
	 * @param list<ProjectionSource> $sources
	 * @param array<string, RecordState> $recordsBySourceKey
	 *
	 * @return list<RepresentationFieldStateItem>
	 */
	private function fieldItemsForSourceRecords(array $sources, array $recordsBySourceKey): array
	{
		$items = [];

		foreach ($sources as $source) {
			$record = $recordsBySourceKey[$source->getPathKey()] ?? null;

			if (! $record instanceof RecordState) {
				throw new SyncException(sprintf(
					'Cannot adopt projection representation because source path "%s" is unresolved.',
					$source->getPathKey(),
				));
			}

			if ($record->getCollection()->getName() !== $source->getCollection()->getName()) {
				throw new StateException(sprintf(
					"Representation source path '%s' targets collection '%s', not '%s'.",
					$source->getPathKey(),
					$source->getCollection()->getName(),
					$record->getCollection()->getName(),
				));
			}

			foreach ($source->getFields() as $fieldBinding) {
				$items[] = new RepresentationFieldStateItem(
					$fieldBinding,
					$record,
					$fieldBinding->getFieldName(),
					$record->getRevision()
				);
			}
		}

		return $items;
	}
}
