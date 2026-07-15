<?php

declare(strict_types=1);

namespace ON\Data\ORM\Representation\State;

use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\Record\RecordState;
use ON\Data\ORM\Representation\Schema\RepresentationFieldSchema;

final class RepresentationFieldStateItem
{
	public static function createOne(
		RepresentationFieldSchema $fieldSchema,
		RecordState $record,
	): self {
		if ($fieldSchema->getCollectionName() !== $record->getCollection()->getName()) {
			throw new StateException(sprintf(
				"Representation schema path '%s' targets collection '%s', not '%s'.",
				$fieldSchema->getPath(),
				$fieldSchema->getCollectionName(),
				$record->getCollection()->getName()
			));
		}

		return new self(
			$fieldSchema,
			$record,
			$fieldSchema->getFieldName(),
			$record->getRevision()
		);
	}

	public function __construct(
		private RepresentationFieldSchema $schema,
		private RecordState $record,
		private string $fieldName,
		private int $baselineRevision,
	) {
	}

	public function getPath(): string
	{
		return $this->schema->getPath();
	}

	public function getSchema(): RepresentationFieldSchema
	{
		return $this->schema;
	}

	public function getRecord(): RecordState
	{
		return $this->record;
	}

	public function getFieldName(): string
	{
		return $this->fieldName;
	}

	public function getBaselineRevision(): int
	{
		return $this->baselineRevision;
	}

	public function hasBaselineValue(): bool
	{
		return $this->record->getHistory()->hasValue($this->baselineRevision, $this->fieldName);
	}

	public function getBaselineValue(): mixed
	{
		return $this->record->getHistory()->getValue($this->baselineRevision, $this->fieldName);
	}

	public function hasCurrentRecordValue(): bool
	{
		return $this->record->hasValue($this->fieldName);
	}

	public function getCurrentRecordValue(): mixed
	{
		return $this->record->getValue($this->fieldName);
	}

	public function withBaselineRevision(int $revision): self
	{
		return new self($this->schema, $this->record, $this->fieldName, $revision);
	}
}
