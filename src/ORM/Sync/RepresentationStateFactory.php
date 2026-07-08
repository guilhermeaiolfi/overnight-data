<?php

declare(strict_types=1);

namespace ON\Data\ORM\Sync;

use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\State\RecordState;
use ON\Data\ORM\State\RepresentationFieldSchema;
use ON\Data\ORM\State\RepresentationFieldStateItem;
use ON\Data\ORM\State\RepresentationRelationStateItem;
use ON\Data\ORM\State\RepresentationSchema;
use ON\Data\ORM\State\RepresentationState;

final class RepresentationStateFactory
{
	/**
	 * @param array<string, RecordState> $recordsBySourceKey
	 */
	public function fromRecords(
		RepresentationSchema $schema,
		array $recordsBySourceKey,
	): RepresentationState {
		return new RepresentationState(
			$schema,
			$this->fieldItemsForRecords($schema, $recordsBySourceKey),
			$this->relationItemsForRecords($schema, $recordsBySourceKey),
		);
	}

	public function createFieldItem(
		RepresentationFieldSchema $fieldSchema,
		RecordState $record,
	): RepresentationFieldStateItem {
		if ($fieldSchema->getCollectionName() !== $record->getCollection()->getName()) {
			throw new StateException(sprintf(
				"Representation schema path '%s' targets collection '%s', not '%s'.",
				$fieldSchema->getPath(),
				$fieldSchema->getCollectionName(),
				$record->getCollection()->getName()
			));
		}

		return new RepresentationFieldStateItem(
			$fieldSchema,
			$record,
			$fieldSchema->getFieldName(),
			$record->getRevision()
		);
	}

	/**
	 * @return list<RepresentationFieldStateItem>
	 */
	private function fieldItemsForRecords(
		RepresentationSchema $schema,
		array $recordsBySourceKey,
	): array {
		$items = [];
		foreach ($schema->getFields() as $fieldSchema) {
			$sourceKey = $fieldSchema->getSourcePathKey();
			$record = $recordsBySourceKey[$sourceKey] ?? null;

			if (! $record instanceof RecordState) {
				if ($fieldSchema->shouldSkipWhenMissing()) {
					continue;
				}

				throw new StateException(sprintf(
					"Cannot create representation state because source path '%s' is unresolved.",
					$sourceKey
				));
			}

			$items[] = $this->createFieldItem($fieldSchema, $record);
		}

		return $items;
	}

	/**
	 * @return list<RepresentationRelationStateItem>
	 */
	private function relationItemsForRecords(RepresentationSchema $schema, array $recordsBySourceKey): array
	{
		if ($schema->getRelations() === []) {
			return [];
		}

		$rootKey = RepresentationFieldSchema::sourcePathKey([]);
		$rootRecord = $recordsBySourceKey[$rootKey] ?? null;
		if (! $rootRecord instanceof RecordState) {
			throw new StateException(sprintf(
				"Cannot create representation state because root source path '%s' is unresolved.",
				$rootKey
			));
		}

		$items = [];
		foreach ($schema->getRelations() as $relationSchema) {
			if ($relationSchema->getOwnerCollectionName() !== $rootRecord->getCollection()->getName()) {
				throw new StateException(sprintf(
					"Representation relation path '%s' targets collection '%s', not '%s'.",
					$relationSchema->getPath(),
					$relationSchema->getOwnerCollectionName(),
					$rootRecord->getCollection()->getName()
				));
			}

			$items[] = new RepresentationRelationStateItem(
				$relationSchema,
				$rootRecord,
				$relationSchema->getRelationName()
			);
		}

		return $items;
	}
}
