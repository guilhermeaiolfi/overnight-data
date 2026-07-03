<?php

declare(strict_types=1);

namespace ON\Data\ORM\Sync;

use ON\Data\ORM\Exception\SyncException;
use ON\Data\ORM\State\RecordState;
use ON\Data\ORM\State\RepresentationFieldBinding;

final class SyncFieldUpdate
{
	public function __construct(
		private RecordState $record,
		private string $field,
		private mixed $value,
		private RepresentationFieldBinding $binding,
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

	public function getBinding(): RepresentationFieldBinding
	{
		return $this->binding;
	}
}
