<?php

declare(strict_types=1);

namespace ON\Data\ORM\Compiler\ManualProjection;

use ON\Data\ORM\Compiler\ProjectionFieldShape;
use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\State\RecordState;
use ON\Data\ORM\State\RepresentationFieldSchema;
use ON\Data\ORM\State\RepresentationFieldStateItem;
use ON\Data\ORM\State\RepresentationSchema;
use ON\Data\ORM\State\RepresentationSchemaMerger;
use ON\Data\ORM\State\RepresentationState;

final class ManualProjectionRepresentationStateBuilder
{
	public function __construct(
		private RepresentationSchemaMerger $schemaMerger = new RepresentationSchemaMerger(),
	) {
	}

	/**
	 * @param list<ProjectionFieldShape> $propertyShapes
	 */
	public function buildOverlay(
		?RepresentationState $existingState,
		RepresentationSchema $manualSchema,
		array $propertyShapes,
	): RepresentationState {
		$recordsByPath = $this->recordsByPathFromShapes($propertyShapes);

		if ($existingState instanceof RepresentationState) {
			$schema = $this->schemaMerger->mergeManualOverlay($existingState->getSchema(), $manualSchema);
			$fieldItems = $existingState->getFieldItems();
			$relationItems = $existingState->getRelationItems();
		} else {
			$schema = $manualSchema;
			$fieldItems = [];
			$relationItems = [];
		}

		foreach ($schema->getFields() as $fieldSchema) {
			if ($this->hasFieldItem($fieldItems, $fieldSchema->getPath())) {
				continue;
			}

			$record = $this->resolveRecordForNewField($existingState, $fieldSchema, $recordsByPath);
			$fieldItems[] = RepresentationFieldStateItem::createOne($fieldSchema, $record);
		}

		return new RepresentationState($schema, $fieldItems, $relationItems);
	}

	/**
	 * @param array<string, RecordState> $recordsByPath
	 */
	private function resolveRecordForNewField(
		?RepresentationState $state,
		RepresentationFieldSchema $fieldSchema,
		array $recordsByPath,
	): RecordState {
		if ($state instanceof RepresentationState) {
			$resolved = $this->resolveRecordForFieldSchema($state, $fieldSchema);
			if ($resolved instanceof RecordState) {
				return $resolved;
			}
		}

		$explicit = $recordsByPath[$fieldSchema->getPath()] ?? null;
		if ($explicit instanceof RecordState) {
			if ($explicit->getCollection()->getName() !== $fieldSchema->getCollectionName()) {
				throw new StateException(sprintf(
					"Manual projection field '%s' resolved to a record of collection '%s' but the schema targets collection '%s'.",
					$fieldSchema->getPath(),
					$explicit->getCollection()->getName(),
					$fieldSchema->getCollectionName(),
				));
			}

			return $explicit;
		}

		throw new StateException(sprintf(
			"Cannot attach manual projection field '%s' because no concrete record state for source path '%s' could be resolved.",
			$fieldSchema->getPath(),
			$fieldSchema->getSourcePathKey(),
		));
	}

	private function resolveRecordForFieldSchema(
		RepresentationState $state,
		RepresentationFieldSchema $fieldSchema,
	): ?RecordState {
		if ($fieldSchema->isRootSource()) {
			return $state->getRootRecord();
		}

		$sourceKey = $fieldSchema->getSourcePathKey();
		foreach ($state->getFieldItems() as $item) {
			if ($item->getSchema()->getSourcePathKey() === $sourceKey) {
				return $item->getRecord();
			}
		}

		return null;
	}

	/**
	 * @param list<ProjectionFieldShape> $propertyShapes
	 *
	 * @return array<string, RecordState>
	 */
	private function recordsByPathFromShapes(array $propertyShapes): array
	{
		$records = [];
		foreach ($propertyShapes as $shape) {
			$source = $shape->getSource();
			if ($source instanceof PropertySource) {
				$records[$shape->getPublicPath()] = $source->getTargetRecord();
			}
		}

		return $records;
	}

	/**
	 * @param list<RepresentationFieldStateItem> $items
	 */
	private function hasFieldItem(array $items, string $path): bool
	{
		foreach ($items as $item) {
			if ($item->getPath() === $path) {
				return true;
			}
		}

		return false;
	}
}
