<?php

declare(strict_types=1);

namespace ON\Data\ORM\Representation\Sync;

use ON\Data\ORM\Exception\SyncException;
use ON\Data\ORM\Record\RecordState;
use ON\Data\ORM\Representation\Schema\RepresentationFieldSchema;
final class SyncFieldUpdate
{
	public function __construct(
		private RecordState $record,
		private string $field,
		private mixed $value,
		private RepresentationFieldSchema $schema,
	) {
		if ($field === '') {
			throw new SyncException('Sync field update field cannot be empty.');
		}
	}

	public function getRecord(): RecordState
	{
		return $this->record;
	}

	public function getField(): string
	{
		return $this->field;
	}

	public function getValue(): mixed
	{
		return $this->value;
	}

	public function getSchema(): RepresentationFieldSchema
	{
		return $this->schema;
	}
}
