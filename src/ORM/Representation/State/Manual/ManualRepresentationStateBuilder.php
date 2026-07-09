<?php

declare(strict_types=1);

namespace ON\Data\ORM\Representation\State\Manual;
use ON\Data\ORM\Representation\Schema\Manual\ManualRepresentationSourceInterface;
use ON\Data\ORM\Representation\Schema\Shape\RepresentationFieldShape;
use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\Record\RecordState;
use ON\Data\ORM\Representation\Schema\RepresentationFieldSchema;
use ON\Data\ORM\Representation\State\RepresentationFieldStateItem;
use ON\Data\ORM\Representation\Schema\RepresentationSchema;
use ON\Data\ORM\Representation\Schema\RepresentationSchemaMerger;
use ON\Data\ORM\Representation\State\RepresentationState;
final class ManualRepresentationStateBuilder
{
	public function __construct(
		private RepresentationSchemaMerger $schemaMerger = new RepresentationSchemaMerger(),
	) {
	}

	/**
	 * @param list<RepresentationFieldShape> $propertyShapes
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
	 * @param list<RepresentationFieldShape> $propertyShapes
	 *
	 * @return array<string, RecordState>
	 */
	private function recordsByPathFromShapes(array $propertyShapes): array
	{
		$records = [];
		foreach ($propertyShapes as $shape) {
			$source = $shape->getSource();
			if ($source instanceof ManualRepresentationSourceInterface) {
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
